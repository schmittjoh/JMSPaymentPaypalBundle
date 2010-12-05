============
Installation
============
Dependencies
------------
This plugin depends on the PaymentBundle_, so you'll need to add this to your kernel
as well even if you don't want to use its persistence capabilities.

Configuration
-------------
::

    // YAML
    paypal.config:
        username: your api username (not your account username)
        password: your api password (not your account password)
        signature: your api signature

If you want to use this plugin in combination with the PaymentBundle_, then you need 
to register this plugin with the payment plugin controller:
::

    // YAML
    payment.config:
        plugins: [paypal_express_checkout]

Usage
=====
You can either interact with the plugin instance directly, or through the payment 
controller. If you only want an easy way to talk to the PayPal API without integrity 
checks or persistence management, then working with the plugin directly is the way 
to go.


.. _PaymentBundle: http://github.com/schmittjoh/PaymentBundle
