<?PHP
/******************************************************************************
** Copyright (C) 2017 Intel Corporation
**
** Project: MCUmgrPHP
**
** Module: crc16.php
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

class crc16
{
	static public function crc16($src, $i, $len, $polynomial, $initial_value, $pad)
	{
		$crc = $initial_value;
		$padding = $pad ? 2 : 0;

		/* src length + padding (if required) */
		while ($i < ($len + $padding))
		{
			for ($b = 0; $b < 8; $b++)
			{
				$divide = $crc & 0x8000;

				$crc = ($crc << 1);

				/* choose input bytes or implicit trailing zeros */
				if ($i < $len) {
					$crc |= !!(ord($src[$i]) & (0x80 >> $b));
				}

				if ($divide != 0) {
					$crc = $crc ^ $polynomial;
				}
			}
			++$i;
		}

		return $crc & 0xffff;
	}
}
?>
