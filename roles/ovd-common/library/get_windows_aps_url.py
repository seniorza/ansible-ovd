#!/usr/bin/python
# encoding: utf-8

# Copyright (C) 2018 Inuvika Inc.
# https://www.inuvika.com
# Author Julien Langlois <j.langlois@inuvika.com> 2018
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

import sys
if sys.version_info.major >= 3:
	from html.parser import HTMLParser
else:
	# Python 2
	from HTMLParser import HTMLParser

from ansible.module_utils.basic import AnsibleModule
import os
import requests


def main():
	# Module settings
	module = AnsibleModule(
		argument_spec=dict(
			ovd_url=dict(required=True),
		),
		supports_check_mode=True,
	)

	ovd_url = module.params["ovd_url"]

	req = requests.get(ovd_url)
	parser = HTMLReadDir()
	parser.feed(req.text)
	
	fname = None
	for f in parser.files:
		if f.endswith("ApplicationServer.exe"):
			fname = f
			break
	
	if not fname:
		module.fail_json(msg="Unable to find the Windows APS setup at the given URL")
	else:
		module.exit_json(changed=True, result=fname)


class HTMLReadDir(HTMLParser):
	def __init__(self):
		HTMLParser.__init__(self)
		self.files = []
		self.folders = []
		self.started = False
		self.items = {}
	
	def handle_starttag(self, tag, attrs):
		if self.started is False and tag == "table":
			self.started = True
		
		if self.started is False:
			return
		
		if tag != "a":
			return
		
		attrs2 = {}
		attrs2.update(attrs)
		if "href" not in attrs2:
			return
		
		target = attrs2["href"]
		if os.path.isabs(target) or "://" in target or target.startswith("mailto:"):
			return
		
		if target in self.items:
			return
		
		self.items[target] = True
		
		if target.endswith("/"):
			target = target[:-1]
			self.folders.append(target)
		else:
			self.files.append(target)
	
	def handle_endtag(self, tag):
		if self.started is True and tag == "table":
			self.started = False


if __name__ == '__main__':
	main()
