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

	public const error_lookup = array(
		['EOK', 'No error'],
		['EUNKNOWN', 'Unknown error'],
		['ENOMEM', 'Insufficient memory'],
		['EINVAL', 'Error in input value'],
		['ETIMEOUT', 'Operation timed out'],
		['ENOENT', 'No such file/entry'],
		['EBADSTATE', 'Current state disallows command'],
		['EMSGSIZE', 'Response too large'],
		['ENOTSUP', 'Command not supported'],
		['ECORRUPT', 'Corrupt'],
		['EBUSY', 'Command blocked by processing of other command'],
		['EACCESSDENIED', 'Access to specific function, command or resource denied'],
		['UNSUPPORTED_TOO_OLD', 'Requested SMP MCUmgr protocol version is not supported (too old)'],
		['UNSUPPORTED_TOO_NEW', 'Requested SMP MCUmgr protocol version is not supported (too new)']
	);

	public const SMP_ERROR_EOK = 0;
	public const SMP_ERROR_EUNKNOWN = 1;

	public const SMP_GROUP_ERROR_OFFSET = 2;

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
		if ($type == smp_error::SMP_ERROR_RC || ($type == smp_error::SMP_ERROR_ERR && ($rc == smp_error::SMP_ERROR_EOK || $rc == smp_error::SMP_ERROR_EUNKNOWN)))
		{
			if (isset(smp_error::$error_lookup[$rc]))
			{
				return smp_error::$error_lookup[$rc][1];
			}
		}
		else if ($type == smp_error::SMP_ERROR_ERR)
		{
			if (isset(smp_error::$error_lookup_functions[$group]))
			{
				if (isset(smp_error::$error_lookup_functions[$group][$rc]))
				{
					return (smp_error::$error_lookup_functions[$group])::error_lookup[($rc - smp_error::SMP_GROUP_ERROR_OFFSET)][1];
				}
			}
		}

		return NULL;
	}

	static function lookup_error_full($type, $group, $rc): ?array
	{
		if ($type == smp_error::SMP_ERROR_RC || ($type == smp_error::SMP_ERROR_ERR && ($rc == smp_error::SMP_ERROR_EOK || $rc == smp_error::SMP_ERROR_EUNKNOWN)))
		{
			if (isset(smp_error::$error_lookup[$rc]))
			{
				return smp_error::$error_lookup[$rc];
			}
		}
		else if ($type == smp_error::SMP_ERROR_ERR)
		{
			if (isset(smp_error::$error_lookup_functions[$group]))
			{
				if (isset(smp_error::$error_lookup_functions[$group][$rc]))
				{
					return (smp_error::$error_lookup_functions[$group])::error_lookup[($rc - smp_error::SMP_GROUP_ERROR_OFFSET)];

				}
			}
		}

		return NULL;
	}
}
?>
