#!/usr/bin/env php
<?php
/**
 * Copyright (C) 2018 Inuvika Inc.
 * https://www.inuvika.com
 * Author David PHAM-VAN <d.phamvan@inuvika.com> 2018
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */


class AdminApi {
	public function __construct($host_, $login_, $password_) {
		$this->host = $host_;
		$this->login = $login_;
		$this->password = $password_;

		$this->stream_context = stream_context_create(array(
			'ssl' => array(
				'verify_peer' => false,
				'verify_peer_name' => false,
				'allow_self_signed' => true
			)
		));

		$this->init();
	}

	public function init() {
		$url = 'https://' . $this->host . '/ovd/service/admin/wsdl';
		try {
			$this->service = new SoapClient($url, array(
				'login' => $this->login,
				'password' => $this->password,
				'location' => 'https://' . $this->host . '/ovd/service/admin',
				'stream_context' => $this->stream_context,
			));
		}
		catch (Exception $e) {
			die($e);
		}
	}

	public function __call($func_, $args_) {
		return $this->service->__call($func_, $args_);
	}
}


abstract class Ansible {
	private $options;

	public function __construct() {
		$this->options = $this->parse($_SERVER["argv"]);

	}

	public function setDefaults($options) {
		$this->options = array_merge($options, $this->options);
	}

	private function parse($args) {
		if (count($args) > 1) {
			$options = file_get_contents($args[1]);
			preg_match_all('/([^=]+)=(?:\'((?:[^\']|\'"\'"\')+)\'|"((?:[^"]|"\'"\'")+)"|([^ ]+)) ?/', $options, $matches, PREG_SET_ORDER);
			$options = array();
			foreach ($matches as $item) {
				$options[$item[1]] = $item[count($item) - 1];
			}
		} else {
			$options = array();
		}
		return $options;
	}

	protected function getBoolean($key) {
		if (array_key_exists($key, $this->options))
			return strtolower($this->options[$key]) == "yes"
				|| strtolower($this->options[$key]) == "true"
				|| strtolower($this->options[$key]) == "1";
		return false;
	}

	protected function getString($key) {
		if (array_key_exists($key, $this->options))
			return (string)$this->options[$key];
		return;
	}

	protected function isDefined($key) {
		return array_key_exists($key, $this->options) && $this->options[$key] !== null;
	}

	public function run() {
		ob_start();
		
		$json_opts = 0;
		if (defined ('JSON_PRETTY_PRINT')) {
			$json_opts|= JSON_PRETTY_PRINT;
		}
		
		try {
			$result = $this->process();
		} catch (Exception $e) {
			$output = ob_get_contents();
			ob_end_clean();
			echo json_encode(array("failed" => true, "msg" => (string)$e, "stdout" => $output), $json_opts) . "\n";
			die();
		}
		$output = ob_get_contents();
		ob_end_clean();
		echo json_encode(array_merge($result, array("stdout" => $output)), $json_opts) . "\n";
	}

	abstract protected function process();
}


class AnsibleSm extends Ansible {
	private $config = null;
	private $config_modified = array();
	private $service;

	protected function getSetting($key) {
		if ($this->config === null) {
			$this->config = $this->service->settings_get();
		}
		$sconfig = $this->config;
		$pkey = explode(".", $key);
		while($item = array_shift($pkey)) {
			$sconfig = $sconfig[$item];
		}
		return $sconfig["value"];
	}

	protected function setSetting($key, $value) {
		$sconfig =& $this->config_modified;
		$pkey = explode(".", $key);
		$final = array_pop($pkey);
		while($item = array_shift($pkey)) {
			if (!array_key_exists($item, $sconfig)) {
				$sconfig[$item] = array();
			}
			$sconfig =& $sconfig[$item];
		}
		$sconfig[$final] = $value;
	}
	
	protected function saveSettings() {
		if (count($this->config_modified)>0) {
			print("New settings: " . json_encode($this->config_modified) . "\n");
			$this->service->settings_set($this->config_modified);
			$this->config_modified = array();
		}
	}

	protected function process() {
		$this->service = new AdminApi($this->getString("host"), $this->getString("user"), $this->getString("password"));

		$changed = false;
		$config = $this->service->getInitialConfiguration();

		if ($this->isDefined("maintenance")) {
			if ($config["system_in_maintenance"] != $this->getBoolean("maintenance")) {
				$this->setSetting("general.system_in_maintenance", $this->getBoolean("maintenance"));
				echo "Set system_in_maintenance to " . (bool)$this->getBoolean("maintenance") . "\n";
				$changed = true;
			}
		}

		if ($this->getBoolean("killall")) {
			$sessions = $this->service->sessions_list();
			while (is_array($sessions) && count($sessions) > 0) {
				echo count($sessions) . " sessions left\n";
				foreach ($sessions as $session) {
					$this->service->session_kill($session["id"]);
					$changed = true;
				}
				sleep(1);
				$sessions = $this->service->sessions_list();
			}
		}
		
		if ($this->isDefined("autoregister")) {
			if ($this->getSetting("general.slave_server_settings.auto_register_new_servers") != $this->getBoolean("autoregister")) {
				$this->setSetting("general.slave_server_settings.auto_register_new_servers", $this->getBoolean("autoregister"));
				echo "Set auto_register_new_servers to " . (bool)$this->getBoolean("autoregister") . "\n";
				$changed = true;
			}
		}
		
		if ($this->isDefined("autoprod")) {
			if ($this->getSetting("general.slave_server_settings.auto_switch_new_servers_to_production") != $this->getBoolean("autoprod")) {
				$this->setSetting("general.slave_server_settings.auto_switch_new_servers_to_production", $this->getBoolean("autoprod"));
				echo "Set auto_switch_new_servers_to_production to " . (bool)$this->getBoolean("autoprod") . "\n";
				$changed = true;
			}
		}
		
		if ($this->isDefined("subscription_key")) {
			$data = @file_get_contents($this->getString("subscription_key"));
			$b64 = base64_encode($data);
			$this->service->certificate_add($b64);
			echo "Install subscription key\n";
			$changed = true;
		}
		
		$this->saveSettings();
		return array("changed" => $changed);
	}
}

$ansible = new AnsibleSm();
$ansible->setDefaults(array(
	"maintenance" => null,
	"killall" => false,
	"autoregister" => null,
	"autoprod" => null,
	"subscription_key" => null,
	"host" => "127.0.0.1",
	"user" => "admin",
	"password" => "admin",
));
$ansible->run();
