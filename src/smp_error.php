<?PHP
/******************************************************************************
** Copyright (C) 2024 Jamie M.
**
** Project: MCUmgrPHP
**
** Module: smp_error.php
**
** Notes:
**
** License: Licensed under the Apache License, Version 2.0 (the "License");
**          you may not use this file except in compliance with the License.
**          You may obtain a copy of the License at
**
**              http://www.apache.org/licenses/LICENSE-2.0
**
**          Unless required by applicable law or agreed to in writing,
**          software distributed under the License is distributed on an
**          "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND,
**          either express or implied. See the License for the specific
**          language governing permissions and limitations under the License.
**
*******************************************************************************/
namespace MCUmgr;

class smp_error extends \Exception
{
	public const SMP_ERROR_NONE = 0;
	public const SMP_ERROR_RC = 1;
	public const SMP_ERROR_ERR = 2;

	private const SMP_RC_ERROR_EOK = 0;
	private const SMP_RC_ERROR_EUNKNOWN = 1;
	private const SMP_RC_ERROR_ENOMEM = 2;
	private const SMP_RC_ERROR_EINVAL = 3;
	private const SMP_RC_ERROR_ETIMEOUT = 4;
	private const SMP_RC_ERROR_ENOENT = 5;
	private const SMP_RC_ERROR_EBADSTATE = 6;
	private const SMP_RC_ERROR_EMSGSIZE = 7;
	private const SMP_RC_ERROR_ENOTSUP = 8;
	private const SMP_RC_ERROR_ECORRUPT = 9;
	private const SMP_RC_ERROR_EBUSY = 10;
	private const SMP_RC_ERROR_EACCESSDENIED = 11;
	private const SMP_RC_ERROR_UNSUPPORTED_TOO_OLD = 12;
	private const SMP_RC_ERROR_UNSUPPORTED_TOO_NEW = 13;

	static private $rc_error_table = array(
		smp_error::SMP_RC_ERROR_EOK => ['EOK', 'No error'],
		smp_error::SMP_RC_ERROR_EUNKNOWN => ['EUNKNOWN', 'Unknown error'],
		smp_error::SMP_RC_ERROR_ENOMEM => ['ENOMEM', 'Insufficient memory'],
		smp_error::SMP_RC_ERROR_EINVAL => ['EINVAL', 'Error in input value'],
		smp_error::SMP_RC_ERROR_ETIMEOUT => ['ETIMEOUT', 'Operation timed out'],
		smp_error::SMP_RC_ERROR_ENOENT => ['ENOENT', 'No such file/entry'],
		smp_error::SMP_RC_ERROR_EBADSTATE => ['EBADSTATE', 'Current state disallows command'],
		smp_error::SMP_RC_ERROR_EMSGSIZE => ['EMSGSIZE', 'Response too large'],
		smp_error::SMP_RC_ERROR_ENOTSUP => ['ENOTSUP', 'Command not supported'],
		smp_error::SMP_RC_ERROR_ECORRUPT => ['ECORRUPT', 'Corrupt'],
		smp_error::SMP_RC_ERROR_EBUSY => ['EBUSY', 'Command blocked by processing of other command'],
		smp_error::SMP_RC_ERROR_EACCESSDENIED => ['EACCESSDENIED', 'Access to specific function, command or resource denied'],
		smp_error::SMP_RC_ERROR_UNSUPPORTED_TOO_OLD => ['UNSUPPORTED_TOO_OLD', 'Requested SMP MCUmgr protocol version is not supported (too old)'],
		smp_error::SMP_RC_ERROR_UNSUPPORTED_TOO_NEW => ['UNSUPPORTED_TOO_NEW', 'Requested SMP MCUmgr protocol version is not supported (too new)']
	);

	private $type = smp_error::SMP_ERROR_NONE;
	private $group = 0;
	private $rc = 0;

	static private $error_lookup_functions = array();

	function __construct($type, $group, $rc)
	{
		if ($type == smp_error::SMP_ERROR_RC)
		{
			$this->type = smp_error::SMP_ERROR_RC;
			$this->rc = $rc;
		}
		else if ($type == smp_error::SMP_ERROR_ERR)
		{
			$this->type = smp_error::SMP_ERROR_ERR;
			$this->group = $group;
			$this->rc = $rc;
		}

	        parent::__construct('SMP error', -1, NULL);
	}

	public function __toString(): string
	{
		if ($this->type == smp_error::SMP_ERROR_ERR)
		{
			return 'SMP version 2 error, group: '.$this->group.', rc: '.$this->rc;
		}
		else if ($this->type == smp_error::SMP_ERROR_RC)
		{
			return 'SMP version 1 error, rc: '.$this->rc;
		}
		else
		{
			return 'No error';
		}
	}

	public function type(): int
	{
		return $this->type;
	}

	public function group(): int
	{
		return $this->group;
	}

	public function rc(): int
	{
		return $this->rc;
	}

	public function error(): array
	{
		return [$this->group, $this->rc];
	}

	static function register_error_lookup_function($group, $function): void
	{
		if (!isset(smp_error::$error_lookup_functions[$group]))
		{
			smp_error::$error_lookup_functions[$group] = $function;
		}
	}

	static function lookup_error($type, $group, $rc): ?string
	{
		if ($type == smp_error::SMP_ERROR_RC)
		{
			if (isset(smp_error::$rc_error_table[$rc]))
			{
				return smp_error::$rc_error_table[$rc][1];
			}
		}
		else if ($type == smp_error::SMP_ERROR_ERR)
		{
			if (isset(smp_error::$error_lookup_functions[$group]))
			{
				if (isset(smp_error::$error_lookup_functions[$group][$rc]))
				{
					return smp_error::$error_lookup_functions[$group][$rc][1];
				}
			}
		}

		return NULL;
	}

	static function lookup_error_full($type, $group, $rc): ?array
	{
		if ($type == smp_error::SMP_ERROR_RC)
		{
			if (isset(smp_error::$rc_error_table[$rc]))
			{
				return smp_error::$rc_error_table[$rc];
			}
		}
		else if ($type == smp_error::SMP_ERROR_ERR)
		{
			if (isset(smp_error::$error_lookup_functions[$group]))
			{
				if (isset(smp_error::$error_lookup_functions[$group][$rc]))
				{
					return smp_error::$error_lookup_functions[$group][$rc];
				}
			}
		}

		return NULL;
	}
}
?>
