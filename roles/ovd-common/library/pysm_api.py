#!/usr/bin/env python
# -*- coding: utf-8 -*-

# Copyright (C) 2018 Inuvika Inc.
# https://www.inuvika.com
# Author David PHAM-VAN <d.phamvan@inuvika.com> 2018
#
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
#
# http://www.apache.org/licenses/LICENSE-2.0
#
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.

import os
import urllib2
import sys
import SOAPpy
from ansible.module_utils.basic import *

import ssl
try:
	ssl._create_default_https_context = ssl._create_unverified_context
except AttributeError, err:
	pass

class Proxy(SOAPpy.WSDL.Proxy):
	#/usr/lib/pymodules/python2.6/SOAPpy/
	def __getattr__(self, name):
		if not self.methods.has_key(name): raise AttributeError, name
		
		callinfo = self.methods[name]
		#self.soapproxy.proxy = SOAPAddress(callinfo.location)
		self.soapproxy.namespace = callinfo.namespace
		self.soapproxy.soapaction = callinfo.soapAction
		return self.soapproxy.__getattr__(name)

# http://pypi.python.org/pypi/SOAPpy/
#  888345: Python 2.3 boolean type serialized as int


class AdminApi:
	def __init__(self, host, login, password):
		self.host = host
		self.login = login
		self.password = password
	
	
	def init(self):
		path  = os.path.dirname(__file__)
		if not os.path.isdir(path):
			path = os.curdir
		
		wsdl_path = os.path.join(path, "ovd.wsdl")
		
		url = "https://%s/ovd/service/admin/wsdl"%(self.host)
		request = urllib2.Request(url)
		
		try:
			stream = urllib2.urlopen(request)
		except urllib2.HTTPError, exc:
			print "HTTP request return code %d (%s)" % (exc.code, exc.msg)
			print " * return: ", exc.read()
			sys.exit(1)
		
		except urllib2.URLError, exc:
			print "Network failure:", exc.reason
			sys.exit(1)
		
		f = open(wsdl_path, "wb")
		f.write(stream.read())
		f.close()
		
		service_url = "https://%s:%s@%s/ovd/service/admin"%(self.login, self.password, self.host)
		
		self.proxy = Proxy(wsdl_path)
		self.proxy.soapproxy = SOAPpy.WSDL.SOAPProxy(service_url)
	
	
	def __getattr__(self, name):
		def func(*args):
			f = getattr(self.proxy, name)
			ret = f(args)
			return self.unserialize(ret)
			
		if name not in self.proxy.methods:
			return None
		
		return func
	
	
	@classmethod
	def unserialize(cls, value):
		if isinstance(value, SOAPpy.Types.structType):
			keys = value._keys()
			if "item" in keys:
				return cls.unserialize(value["item"])
			
			if "key" in keys and "value" in keys:
				return {cls.unserialize(value["key"]): cls.unserialize(value["value"])}
		
		if isinstance(value, SOAPpy.Types.arrayType):
			value2 = []
			for item in value:
				value2.append(cls.unserialize(item))
			
			return value2
		
		elif type(value) is list:
			if len(value) == 0:
				return []
			
			value2 = None
			
			for item in value:
				v = cls.unserialize(item)
				if type(v) is dict:
					if value2 is None:
						value2 = {}
					elif type(value2) is not dict:
						print "error"
						exit(2)
					
					value2.update(v)
				else:
					if value2 is None:
						value2 = []
					elif type(value2) is not list:
						print "error"
						exit(2)
					
					value2.append(v)
			
			return value2
		
		return value


	@classmethod
	def serialize(cls, value):
		if isinstance(value, dict):
			obj = []
			for key, val in value.iteritems():
				inner = {"key":key,"value": cls.serialize(val)}
				obj.append(inner)
			return obj
		
		return value


def run(params):
	maintenance = params['maintenance']
	killall = params['killall']
	host = params['host']
	user = params['user']
	password = params['password']

	api = AdminApi(host, user, password)
	api.init()
	
	changed = False
	config = api.getInitialConfiguration()
	
	ret = ''
	if bool(config["system_in_maintenance"]) != maintenance:
		if api.proxy.settings_set({"general" :{"system_in_maintenance": int(maintenance)}}) == 0:
			changed = True
	
	if killall:
		sessions = api.sessions_list()
		print sessions

	#
	# if ($this->getBoolean("killall")) {
	# 	$sessions = $service;
	# 	while (is_array($sessions) && count($sessions) > 0) {
	# 		echo count($sessions) . " sessions left\n";
	# 		foreach ($sessions as $session) {
	# 			$service->session_kill($session["id"]);
	# 			$changed = true;
	# 		}
	# 		sleep(1);
	# 		$sessions = $service->sessions_list();
	# 	}
	# }
	#
	#
	# api.call(function_name, function_args)
	#
	# ls = run("ovd-slaveserver-role -m '%s' ls" % dest)
	#
	# if state == 'present' and ls.find(name) < 0:
	# 	run("ovd-slaveserver-role -m '%s' add %s" % (dest, name))
	# 	changed = True
	# elif state == 'absent' and ls.find(name) >= 0:
	# 	run("ovd-slaveserver-role -m '%s' del %s" % (dest, name))
	# 	changed = True
	# else:
	# 	changed = False
	#
	# # Change file attributes if needed
	# if os.path.isfile(dest):
	# 	file_args = module.load_file_common_arguments(module.params)
	# 	changed = module.set_fs_attributes_if_different(file_args, changed)
	

def main():
	# Module settings
	module = AnsibleModule(
		argument_spec=dict(
			maintenance=dict(type='bool'),
			killall=dict(type='bool'),
			host=dict(default='127.0.0.1'),
			user=dict(default='admin'),
			password=dict(default='admin'),
		),
		supports_check_mode=True,
	)

	try:
		changed = run(module.params)
	except:
		module.exit_json(failed=True, msg='%s: %s' % (sys.exc_info()[0].__name__, str(sys.exc_info()[1])))
	
	# Print status of the change
	module.exit_json(changed=changed)

def test():
	params = dict(
		maintenance = True,
		killall = True,
		host = '10.8.40.113',
		user = 'admin',
		password = 'admin'
	)
	
	run(params)


if __name__ == '__main__':
	test()


"""class AdminApi {
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
		$path  = dirname(__FILE__);
		if (! is_dir($path)) {
			$path = '.';
		}

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

	public function __getFunctions() {
		return $this->service->__getFunctions();
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
			preg_match_all("/([^=]+)=(\"([^\"]+)\"|[^ ]+) ?/", $options, $matches, PREG_SET_ORDER);
			$options = array();
			foreach ($matches as $item) {
				$options[$item[1]] = count($item) == 4 ? $item[3] : $item[2];
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

	public function run() {
		ob_start();
		try {
			$result = $this->process();
		} catch (Exception $e) {
			$output = ob_get_contents();
			ob_end_clean();
			echo json_encode(array("failed" => true, "msg" => (string)$e, "stdout" => $output)) . "\n";
			die();
		}
		$output = ob_get_contents();
		ob_end_clean();
		echo json_encode(array_merge($result, array("stdout" => $output))) . "\n";
	}

	abstract protected function process();
}


class AnsibleSm extends Ansible {
	protected function process() {
		$service = new AdminApi($this->getString("host"), $this->getString("user"), $this->getString("password"));

		$changed = false;
		$config = $service->getInitialConfiguration();

		if ($config["system_in_maintenance"] != $this->getBoolean("maintenance")) {
			$service->settings_set(array("general" => array("system_in_maintenance" => $this->getBoolean("maintenance"))));
			$changed = true;
		}


		if ($this->getBoolean("killall")) {
			$sessions = $service->sessions_list();
			while (is_array($sessions) && count($sessions) > 0) {
				echo count($sessions) . " sessions left\n";
				foreach ($sessions as $session) {
					$service->session_kill($session["id"]);
					$changed = true;
				}
				sleep(1);
				$sessions = $service->sessions_list();
			}
		}

		return array("changed" => $changed);
	}
}

$ansible = new AnsibleSm();
$ansible->setDefaults(array(
	"maintenance" => false,
	"killall" => false,
	"host" => "127.0.0.1",
	"user" => "admin",
	"password" => "admin",
));
$ansible->run();
"""
