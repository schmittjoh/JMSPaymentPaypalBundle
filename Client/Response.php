<?php

namespace JMS\Payment\PaypalBundle\Client;

use Symfony\Component\HttpFoundation\ParameterBag;

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

class Response
{
    public $body;

    public function __construct(array $parameters)
    {
        $this->body = new ParameterBag($parameters);
    }

    public function isSuccess()
    {
        $ack = $this->body->get('ACK');

        return 'Success' === $ack || 'SuccessWithWarning' === $ack;
    }

    public function isPartialSuccess()
    {
        return 'PartialSuccess' === $this->body->get('ACK');
    }

    public function isError()
    {
        $ack = $this->body->get('ACK');

        return 'Failure' === $ack || 'FailureWithWarning' === $ack || 'Warning' === $ack;
    }

    public function getErrors()
    {
        $errors = array();
        $i = 0;
        while ($this->body->has('L_ERRORCODE'.$i)) {
            $errors[] = array(
                'code' => $this->body->get('L_ERRORCODE'.$i),
                'short_message' => $this->body->get('L_SHORTMESSAGE'.$i),
                'long_message' => $this->body->get('L_LONGMESSAGE'.$i),
            );

            ++$i;
        }

        return $errors;
    }

    public function __toString()
    {
        if ($this->isError()) {
            $str = 'Debug-Token: '.$this->body->get('CORRELATIONID')."\n";

            foreach ($this->getErrors() as $error) {
                $str .= "{$error['code']}: {$error['short_message']} ({$error['long_message']})\n";
            }
        } else {
            $str = var_export($this->body->all(), true);
        }

        return $str;
    }
}
