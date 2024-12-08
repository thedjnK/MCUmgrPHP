# MCUmgrPHP

## Preface

Note that MCUmgrPHP is currently in the process of being developed for an initial release.

## About

MCUmgrPHP is a cross-platform MCUmgr library for PHP, designed for issuing commands to devices, created in PHP 7.

## Features

* Shares same base and class implementations as MCUmgr plugin from AuTerm https://github.com/thedjnK/AuTerm/
* Supports transports:
  - LoRaWAN
  - UART
* Supports MCUmgr groups:
  - Image management
  - Filesystem management
  - OS management
  - Statistic management
  - Shell management
  - Settings management
  - Zephyr basic management
  - Enumeration management

## Examples

Examples are provided in the `examples` directory

* [decode.php](examples/decode.php) shows how to parse SMP messages, a hex SMP message can be provided on the command line and details will be shown if it is valid, this does not use any sort of event loop
* [commands.php](examples/commands.php) shows how to issue commands to devices, it uses the UART transport to issue an OS management group echo command and image management group state get command, and outputs the data

These examples should be ran from the top level directory, not from within the `examples` directory

## Help and contributing

Users are welcome to open issues and submit pull requests to have features merged. PRs on github should target the `main` branch, PRs on the internal git server should target the `develop` branch.

## License

MCUmgrPHP is released under the [Apache 2.0 license](https://github.com/thedjnK/MCUmgrPHP/blob/master/LICENSE).
