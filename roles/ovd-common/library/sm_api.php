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

	protected static $base_parameters = [
		"_ansible_check_mode" => [
			'type' => 'boolean',
			'required' => false,
			'default' => false,
		],
		'_ansible_diff' => [
			'type' => 'boolean',
			'required' => false,
			'default' => false,
		],
		'_ansible_no_log' => [
			'type' => 'boolean',
			'required' => false,
			'default' => false,
		],
		'_ansible_verbosity' => [
			'type' => 'int',
			'required' => false,
			'default' => 0,
		],
	];
	protected $parameters = [];

	public function __construct() {
		$this->parameters = array_merge($this->parameters, self::$base_parameters);
	}

	private function init_parameters($args_) {
		$this->options = [];

		$args_ = json_decode ($args_, true);
		if (!is_array($args_)) {
			throw new Exception('Invalid arguments passed to the module. Expect JSON dict');
		}

		// also check that array is dict and not list

		foreach ($args_ as $key => $value) {
			if (!isset($this->parameters[$key])) {
				if (strpos($key, '_ansible_') === 0) {
					continue;
				}
				else {
					throw new Exception('Unknown parameter "'.$key.'"');
				}
			}

			switch($this->parameters[$key]['type']) {
				case 'int':
					if (!is_numeric($value)) {
						throw new Exception('Parameter "'.$key.'" expects type int. Type provided: '.var_export($value, true));
					}

					$this->options[$key] = intval($value);
					break;
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
					if (!is_bool($value)) {
						throw new Exception('Parameter "'.$key.'" expects type boolean. Type provided: '.var_export($value, true));
					}

					$this->options[$key] = $value;
					break;
				case 'dict':
					if (!is_array($value)) {
						throw new Exception('Parameter "'.$key.'" expects type dict (3). Type provided: '.var_export($value, true));
					}

					$this->options[$key] = $value;
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


	public function run($args_) {
		ob_start();
		try {
			$this->init_parameters($args_);
			$result = $this->process();
		}
		catch (Exception $e) {
			$output = ob_get_contents();
			ob_end_clean();

			$result = [
				"failed" => true,
				"msg" => (string)$e,
			];

			if (@$this->options['_ansible_verbosity']) {
				$result["stdout"] = $output;
			}

			$this->json_exit($result, 2);
		}

		$output = ob_get_contents();
		ob_end_clean();

		if (@$this->options['_ansible_verbosity'] > 1) {
			$result["stdout"] = $output;
		}

		$this->json_exit($result);
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
		"settings" => [
			'type' => 'dict',
			'required' => false,
			'default' => [],
		],
		"purge_all_sessions" => [
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

	protected function process() {
		$this->service = new AdminApi(
			$this->options["host"],
			$this->options["user"],
			$this->options["password"]
		);

		$changed = false;
		$diff = [];

		if ($this->options["settings"]) {
			$config_modified = [];
			foreach($this->options["settings"] as $key => $value) {
				$value_saved = $this->getSetting($key);
				$diff['before'][$key] = $value_saved;
				$diff['after'][$key] = $value;
				if ($value == $value_saved) {
					continue;
				}

				$config_modified[$key] = $value;
				$changed = true;
			}

			if ($config_modified && !$this->options['_ansible_check_mode']) {
				$ret = $this->service->settings_set($new_settings);
				if (!$ret) {
					throw new Exception('settings_set returned unexpected value '.var_export($ret, false));
				}
			}
		}

		if ($this->options["purge_all_sessions"]) {
			$sessions = $this->service->sessions_list();
			if ($sessions) {
				$changed = true;
				$diff['before']['purge_all_sessions'] = array_keys($sessions);
				$diff['after']['purge_all_sessions'] = [];
				if (!$this->options['_ansible_check_mode']) {
					while (is_array($sessions) && count($sessions) > 0) {
						echo count($sessions) . " sessions left\n";
						foreach ($sessions as $session) {
							$this->service->session_kill($session["id"]);
						}

						sleep(1);
						$sessions = $this->service->sessions_list();
					}
				}
			}
		}

		if ($this->options["subscription_key"]) {
			$changed = true;

			if (!$this->options['_ansible_check_mode']) {
				$data = @file_get_contents($this->options["subscription_key"]);
				$b64 = base64_encode($data);
				$ret = $this->service->certificate_add($b64);
				if (!$ret) {
					throw new Exception('certificate_add returned unexpected value '.var_export($ret, false));
				}
			}
		}

		$ret = ["changed" => $changed];
		if ($this->options['_ansible_diff'] && !$this->options['_ansible_no_log']) {
			$ret['diff'] = $diff;
		}

		return $ret;
	}
}

$args = <<<EOD
<<INCLUDE_ANSIBLE_MODULE_JSON_ARGS>>
EOD;

$ansible = new AnsibleSm();
$ansible->run($args);
