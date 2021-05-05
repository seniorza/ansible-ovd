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

		$this->service = new SoapClient(
			'https://' . $this->host . '/ovd/service/admin/wsdl',
			[
				'login' => $this->login,
				'password' => $this->password,
				'location' => 'https://' . $this->host . '/ovd/service/admin',
				'stream_context' => stream_context_create([
					'ssl' => [
						'verify_peer' => false,
						'verify_peer_name' => false,
						'allow_self_signed' => true,
					],
				]),
			]
		);
	}

	public function __call($func_, $args_) {
		return $this->service->__call($func_, $args_);
	}
}


abstract class Ansible {
	private static $string_boolean_false = ["no",  "false", "0"];
	private static $string_boolean_true  = ["yes", "true",  "1"];

	protected $options;

	protected $parameters = [];

	public function __construct() {
		$this->parameters = array_merge($this->parameters);
	}

	private function init_parameters() {
		$this->options = [];

		if (count($_SERVER["argv"]) < 2) {
			throw new Exception('Parameter file is missing');
		}

		$data = file_get_contents($_SERVER["argv"][1]);
		if (!$data) {
			throw new Exception('Parameter file is empty');
		}

		preg_match_all('/([^=]+)=(?:\'((?:[^\']|\'"\'"\')+)\'|"((?:[^"]|"\'"\'")+)"|([^ ]+)) ?/', $data, $matches, PREG_SET_ORDER);
		if (!$matches) {
			throw new Exception('Parameter file is empty');
		}

		foreach ($matches as $item) {
			$key = $item[1];
			$value = $item[count($item) - 1];
			if (!isset($this->parameters[$key])) {
				if (strpos($key, '_ansible_') === 0) {
					continue;
				}
				else {
					throw new Exception('Unknown parameter "'.$key.'"');
				}
			}

			switch($this->parameters[$key]['type']) {
				case 'string':
					if (
						isset($this->parameters[$key]['choices'])
							&&
						is_array($this->parameters[$key]['choices'])
							&&
						!in_array($value, $this->parameters[$key]['choices'])
					) {
						throw new Exception('Parameter "'.$key.'", value '.var_export($value, true).' not in: '.implode(', ', $this->parameters[$key]['choices']));
					}

					$this->options[$key] = $value;
					break;
				case 'boolean':
					if (in_array(strtolower($value), self::$string_boolean_false)) {
						$this->options[$key] = false;
					}
					else if (in_array(strtolower($value), self::$string_boolean_true)) {
						$this->options[$key] = true;
					}
					else {
						throw new Exception('Parameter "'.$key.'" expects type boolean. Type provided: '.var_export($value, true));
					}

					break;
				default:
					throw new Exception('Internal error');
			}
		}

		foreach($this->parameters as $key => $parameter) {
			if (!array_key_exists($key, $this->options)) {
				if (array_key_exists('default', $parameter)) {
					$this->options[$key] = $parameter['default'];
				}
				else if ($parameter['required']) {
					throw new Exception('Parameter "'.$key.'" is required');
				}
			}
		}
	}


	public function run() {
		ob_start();
		try {
			$this->init_parameters();
			$result = $this->process();
		}
		catch (Exception $e) {
			$output = ob_get_contents();
			ob_end_clean();
			$this->json_exit([
				"failed" => true,
				"msg" => (string)$e,
				"stdout" => $output,
			], 2);
		}

		$output = ob_get_contents();
		ob_end_clean();
		$this->json_exit(array_merge(
			$result,
			["stdout" => $output]
		));
	}

	abstract protected function process();


	protected function json_exit($data, $exit_code=0) {
		$json_opts = 0;
		if (defined ('JSON_PRETTY_PRINT')) {
			$json_opts|= JSON_PRETTY_PRINT;
		}

		echo json_encode($data)."\n";
		exit($exit_code);
	}
}


class AnsibleSm extends Ansible {
	protected $parameters = [
		"host" => [
			'type' => 'string',
			'required' => false,
			'default' => "127.0.0.1",
		],
		"user" =>  [
			'type' => 'string',
			'required' => true,
		],
		"password" =>  [
			'type' => 'string',
			'required' => true,
		],

		"autoprod" => [
			'type' => 'boolean',
			'required' => false,
			'default' => false,
		],
		"autoregister" => [
			'type' => 'boolean',
			'required' => false,
			'default' => false,
		],
		"purge_all_sessions" => [
			'type' => 'boolean',
			'required' => false,
			'default' => false,
		],
		"maintenance" => [
			'type' => 'boolean',
			'required' => false,
			'default' => false,
		],
		"subscription_key" => [
			'type' => 'string',
			'required' => false,
			'default' => null,
		],
	];

	private $config = null;
	private $config_modified = [];
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
				$sconfig[$item] = [];
			}

			$sconfig =& $sconfig[$item];
		}

		$sconfig[$final] = $value;
	}

	protected function saveSettings() {
		if (count($this->config_modified)>0) {
			print("New settings: " . json_encode($this->config_modified) . "\n");
			$this->service->settings_set($this->config_modified);
			$this->config_modified = [];
		}
	}

	protected function process() {
		$this->service = new AdminApi(
			$this->options["host"],
			$this->options["user"],
			$this->options["password"]
		);

		$changed = false;
		$config = $this->service->getInitialConfiguration();

		if (!is_null($this->options["maintenance"])) {
			if ($config["system_in_maintenance"] != $this->options["maintenance"]) {
				$this->setSetting(
					"general.system_in_maintenance",
					$this->getBoolean("maintenance")
				);

				echo "Set system_in_maintenance to " . (bool)$this->options["maintenance"] . "\n";
				$changed = true;
			}
		}

		if (!is_null($this->options["autoregister"])) {
			if ($this->getSetting("general.slave_server_settings.auto_register_new_servers") != $this->options["autoregister"]) {
				$this->setSetting(
					"general.slave_server_settings.auto_register_new_servers",
					$this->options["autoregister"]
				);

				echo "Set auto_register_new_servers to " . (bool)$this->options["autoregister"] . "\n";
				$changed = true;
			}
		}

		if (!is_null($this->options["autoprod"])) {
			if ($this->getSetting("general.slave_server_settings.auto_switch_new_servers_to_production") != $this->options["autoprod"]) {
				$this->setSetting(
					"general.slave_server_settings.auto_switch_new_servers_to_production",
					$this->options["autoprod"]
				);

				echo "Set auto_switch_new_servers_to_production to " . (bool)$this->options["autoprod"] . "\n";
				$changed = true;
			}
		}

		$this->saveSettings();

		if ($this->options["purge_all_sessions"]) {
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

		if ($this->options["subscription_key"]) {
			$data = @file_get_contents($this->options["subscription_key"]);
			$b64 = base64_encode($data);
			$this->service->certificate_add($b64);
			echo "Install subscription key\n";
			$changed = true;
		}

		return ["changed" => $changed];
	}
}

$ansible = new AnsibleSm();
$ansible->run();
