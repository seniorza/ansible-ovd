# Ansible roles for Inuvika OVD Enterprise

![Inuvika Inc.](https://archive.inuvika.com/theme/media/logo-dark.png)

<https://www.inuvika.com>

The Ansible-ovd project is a set of Free and Open Source tools that let users install
and manage a full Inuvika OVD Enterprise environment on both Linux and Windows servers.

Ansible is used as the configuration management platform. Inuvika provides a collection
of Ansible roles that manage configuration of all OVD services, as well as an example playbook that
ties them together into a highly integrated environment.

Ansible roles and playbooks provided by Inuvika are designed to manage a single Proof of Concept (POC)
host or a set of hosts for production use. The hosts in question can be physical or virtual machines.

## Support

The roles support the following servers:

* CentOS 7.x 64 bits
* Red Hat Enterprise Linux 7.x 64 bits
* Ubuntu 18.04 LTS server (Bionic Beaver) 64 bits
* Ubuntu 16.04 LTS server (Xenial Xerus) 64 bits
* Ubuntu 14.04 LTS server (Trusty Tahr) 64 bits
* Windows Server 2019 (available for OVD version 2.8+)
* Windows Server 2016
* Windows Server 2012 R2

Please ensure the virtual machine on which you are installing OVD meets the
system requirements outlined in the [Installation and Configuration Guide](https://docs.inuvika.com/installation_and_configuration_guide/).

### Exceptions

* Windows Server 2008 R2

   While Inuvika officially supports Windows 2008 R2 for OVD versions 2.7.3, it
   is not available via Ansible.

   Ansible requires a minimum version of PowerShell that 2008 R2
   does not provide. Customers wishing to deploy 2008 R2 will have
   to manually configure their Windows application servers.

## Install Ansible and Inuvika OVD roles

Please refer to the official documentation on <https://docs.ansible.com/ansible/latest/index.html>

We provide here the commands we use to get started, assuming
`python pip` is already available on the system.

While Ansible cannot run on a Windows host, it can manage
Windows hosts if executed on Windows under the Windows Subsystem
for Linux (WSL).

> The Windows Subsystem for Linux is not supported by Microsoft,
> Ansible, or Inuvika. Inuvika recommends that it not be used for
> production systems.

To install Ansible using `pip`, use this commands:

```shell
git clone https://github.com/inuvika/ansible-ovd
cd ansible-ovd
pip install --user -r requirements.pip
```

## Version code

Throughout this document, download links will use a version code specific to the version
of OVD you are using.

You will find the *version code* on the [Inuvika OVD supported versions page](https://support.inuvika.com/portal/kb/articles/documentation).
You may also [contact Inuvika](https://www.inuvika.com/contact-us/) to request the code.

## Install and Manage your Inuvika OVD Enterprise farm

1. Copy or rename the example folder to a custom name and cd to this folder

2. Edit the file inventory.cfg

   * Update the credentials:
      if you have an ssh key set up for your root user, use `ansible_user=root` and `ansible_become=no`.

      To generate ssh keys, use the command `ssh-keygen` and copy the contents of the generated `.pub` file
      to the Linux servers in `~root/.ssh/authorized_keys` and `sudo chmod 600 ~root/.ssh/authorized_keys`.

   * In the *inventory.cfg* file, replace `{VERSION_CODE}` with the *version code* as described
     in the [Version code section](#version-code "Version Code").

   * In the section `[all]` add all your machines with:

      ```ini
      [all]
      machine_name ansible_host=ip_address
      ```

   * In the other sections, add the machine names to the features you want to install.
   * In the section `[woas:vars]` you can specify the specific credentials for the Windows Servers.

3. Run this powershell script to enable winrm on the Windows servers: [ConfigureRemotingForAnsible.ps1](https://raw.githubusercontent.com/ansible/ansible/devel/examples/scripts/ConfigureRemotingForAnsible.ps1)

4. Execute ansible:

   ```shell
   ansible-playbook ovd-farm.yml
   ```

## Contributing

If you are interested in fixing issues and contributing directly to the code base,
please see the document [How to Contribute](CONTRIBUTING.md).

## Feedback

* Ask a question at <support@inuvika.com>.
* [Tweet](https://twitter.com/InuvikaInc) us with any other feedback.

## License

Copyright (c) [Inuvika Inc.](https://www.inuvika.com) All rights reserved.

Licensed under the [Apache 2.0](LICENSE.md) License.

## About OVD Enterprise

OVD Enterprise is a virtualized application delivery platform for
any size commercial organization. It delivers virtualized Windows
and Linux applications, and shared desktops, to any endpoint device
using an Inuvika client or HTML 5 web browser. OVD installs on Linux
infrastructure hosted on-site, or on cloud platforms like Microsoft Azure
and Google Cloud Platform.

For more technical details, refer to our Reference Documents
section on <https://inuvika.com/support>.
