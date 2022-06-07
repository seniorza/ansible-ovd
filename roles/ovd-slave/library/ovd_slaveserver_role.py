#!/usr/bin/python
# encoding: utf-8

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

def run(cmd):
	p = subprocess.Popen(cmd, stdout=subprocess.PIPE, shell=True)
	data = p.stdout.read().strip()
	return data.decode("utf-8")

def main():
	# Module settings
	module = AnsibleModule(
		argument_spec=dict(
			dest=dict(required=True),
			name=dict(required=True),
			state=dict(choices=['present', 'absent'], default='present'),
		),
		add_file_common_args=True,
		supports_check_mode=True,
	)

	name = module.params['name']
	state = module.params['state']
	dest = module.params['dest']

	ls = run("ovd-slaveserver-role -m '%s' ls" % dest)
	
	if state == 'present' and ls.find(name) < 0:
		run("ovd-slaveserver-role -m '%s' add %s" % (dest, name))
		changed = True
	elif state == 'absent' and ls.find(name) >= 0:
		run("ovd-slaveserver-role -m '%s' del %s" % (dest, name))
		changed = True
	else:
		changed = False

	# Change file attributes if needed
	if os.path.isfile(dest):
		file_args = module.load_file_common_arguments(module.params)
		changed = module.set_fs_attributes_if_different(file_args, changed)

	# Print status of the change
	module.exit_json(changed=changed, repo=name, state=state, ls=ls)


# Import module snippets
from ansible.module_utils.basic import *


if __name__ == '__main__':
	main()
