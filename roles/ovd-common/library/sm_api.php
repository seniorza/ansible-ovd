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

abstract class AdminApi {
	protected $host;
	protected $login;
	protected $password;

	public function __construct($host_, $login_, $password_) {
		$this->host = $host_;
		$this->login = $login_;
		$this->password = $password_;
	}

	abstract public function __call($func_, $args_);

	public static function factory($mode_, $host_, $login_, $password_) {
		$class = 'AdminAPI_'.ucfirst($mode_);
		return new $class($host_, $login_, $password_);
	}
}


class AdminAPI_Soap extends AdminApi {
	public function __construct($host_, $login_, $password_) {
		parent::__construct($host_, $login_, $password_);

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


class AdminAPI_Rest extends AdminApi {
	public function __construct($host_, $login_, $password_) {
		parent::__construct($host_, $login_, $password_);

		$this->base_url = 'https://'.$this->host.'/ovd/service/admin/';
	}


	public function __call($func_, $args_) {
		$payload = null;
		if ($args_) {
			$payload = ['args' => $args_];
		}

		$res = $this->curl_request('POST', $func_, $payload);
		if ($res['rc'] != 200) {
			if (!isset($res['data']['error'])) {
				throw new Exception('Communication error');
			}
			if (@$res['data']['error']['code'] == 'not_authorized') {
				throw new Exception('You are not allowed to perform this action');
			}

			throw new APIException(
				$res['data']['error']['code'],
				@$res['data']['error']['message'],
				$res['data']['error']
			);
		}

		return $res['data'];
	}

	protected function curl_request($method, $url_, $data_in_ = null) {
		$socket = curl_init($this->base_url.$url_);
		curl_setopt($socket, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($socket, CURLOPT_SSL_VERIFYPEER, 0);
		curl_setopt($socket, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($socket, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($socket, CURLOPT_FILETIME, true);
		curl_setopt($socket, CURLOPT_CUSTOMREQUEST, $method);

		$headers = ['Connection: close'];
		if ($data_in_) {
			curl_setopt($socket, CURLOPT_POSTFIELDS, json_encode($data_in_));
			$headers []= 'Content-Type: application/json';
		}

		curl_setopt($socket, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($socket, CURLOPT_HEADER, 1);
		curl_setopt($socket, CURLOPT_USERPWD, $this->login.':'.$this->password);

		$data_out = curl_exec($socket);

		$rc = curl_getinfo($socket, CURLINFO_HTTP_CODE);
		$headers_size = curl_getinfo($socket, CURLINFO_HEADER_SIZE);
		curl_close($socket);
		$headers = substr($data_out, 0, $headers_size);
		$body = substr($data_out, $headers_size);

		$result = [
			'rc' => $rc,
		];

		if (preg_match('#Content-Type: application/json(;|$)#i', $headers)) {
			$result['data'] = json_decode($body, true);
		}
		else {
			$result['raw'] = $body;
		}

		return $result;
	}
}

class APIException extends Exception {
	public $faultcode;
	public $detail;

	public function __construct($faultcode, $message, $detail=null) {
		parent::__construct($message);
		$this->faultcode = $faultcode;
		$this->detail = $detail;
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
				case 'dict':
					if (substr($value, 0, 1) != '{' || substr($value, -1) != '}') {
						throw new Exception('Parameter "'.$key.'" expects type dict (1). Type provided: '.var_export($value, true));
					}

					if (strlen($value) > 2 && strpos($value, '\'"\'"\'') === false) {
						throw new Exception('Parameter "'.$key.'" expects type dict (2). Type provided: '.var_export($value, true));
					}

					// Convert strange ansible data structure to JSON
					$value = str_replace('\'"\'"\'', "'", $value);
					$value = str_replace('"', '\"', $value);
					$value = str_replace("'", '"', $value);
					$value = preg_replace_callback(
						'/": (True|False|Null)(, |})/',
						function ($matches) {
							return strtolower($matches[0]);
						},
						$value
					);

					$value = json_decode ($value, true);
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


	public function run() {
		ob_start();
		try {
			$this->init_parameters();
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
		"api" => [
			'type' => 'string',
			'required' => true,
			'choices' => ["rest", "soap"],
		],
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
		$this->service = AdminApi::factory(
			$this->options["api"],
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
			$sessions = $this->service->sessions_list(null);
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
						$sessions = $this->service->sessions_list(null);
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

$ansible = new AnsibleSm();
$ansible->run();
