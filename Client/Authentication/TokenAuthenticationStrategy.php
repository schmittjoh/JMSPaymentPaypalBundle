<?php

namespace JMS\Payment\PaypalBundle\Client\Authentication;

use JMS\Payment\CoreBundle\BrowserKit\Request;

/*
 * Copyright 2010 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

class TokenAuthenticationStrategy implements AuthenticationStrategyInterface
{
    protected $username;
    protected $password;
    protected $signature;

    public function __construct($username, $password, $signature)
    {
        $this->username = $username;
        $this->password = $password;
        $this->signature = $signature;
    }

    public function authenticate(Request $request)
    {
        $request->request->set('PWD', $this->password);
        $request->request->set('USER', $this->username);
        $request->request->set('SIGNATURE', $this->signature);
    }

    public function getApiEndpoint($isDebug)
    {
        if ($isDebug) {
            return 'https://api-3t.sandbox.paypal.com/nvp';
        } else {
            return 'https://api-3t.paypal.com/nvp';
        }
    }
}
