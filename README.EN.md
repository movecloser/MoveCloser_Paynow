[**Polska wersja**][ext0]

# Paynow plugin for Magento 1.6+

[![Build Status](https://travis-ci.com/pay-now/paynow-magento.svg?branch=master)](https://travis-ci.com/pay-now/paynow-magento)
[![Latest Version](https://img.shields.io/github/release/pay-now/paynow-magento.svg)](https://github.com/pay-now/paynow-magento/releases)
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg)](LICENSE)

The Paynow plugin adds quick bank transfers and BLIK payments to a Magento store.

## Table of Contents

- [Prerequisites](#prerequisites)
- [Installation](#installation)
- [Configuration](#configuration)
- [FAQ](#FAQ)
- [Sandbox](#sandbox)
- [Support](#support)
- [License](#license)

## Prerequisites

- PHP since 7.1
- Magento version from 1.6 to 2.0

## Installation
### Manually via FTP
1. Download the paynow.zip file from [Github repository][ext1] and save to your computer
2. Extract downloaded file
3. Copy `app`, `lib` oraz `skin` to your Magento store main directory
4. Go to **System** > **Cache Management** > **Flush Magento Cache**

### Modman
Plugin contains support for `modman` script.

## Configuration

1. Go to administration page
2. Go to **System > Configuration > Sales > Payment Methods**.
3. From available payment methods choose **Paynow**
4. After changes save your configuration

## FAQ

**How to configure the return address?**

The return address will be set automatically for each order. There is no need to manually configure this address.

**How to configure the notification address?**

In the Paynow merchant panel go to the tab `Settings > Shops and poses`, in the field `Notification address` set the address:
`https://twoja-domena.pl/paynow/payment/notifications`.

## Sandbox

To be able to test our Paynow Sandbox environment, register [here][ext2].

## Support

If you have any questions or issues, please contact our support at support@paynow.pl.

If you wish to learn more about Paynow visit our website: https://www.paynow.pl

## License

MIT license. For more information, see the LICENSE file.

[ext0]: README.md
[ext1]: https://github.com/pay-now/paynow-magento/releases/latest
[ext2]: https://panel.sandbox.paynow.pl/auth/register