<?php

namespace Bot2Hook;

class Signature
{
    /** @var string */
    protected $key;

    public function __construct($key)
    {
        $this->key = $key;
    }

    public function isValid($signature, $uri, array $params)
    {
        $signature_computed = $this->generate($uri, $params);
        return $signature_computed == $signature;
    }

    public function generate($uri, $params)
    {
        $signed_data = $uri;
        ksort($params);
        foreach ($params as $key => $value) {
            $signed_data .= $key;
            $signed_data .= $value;
        }

        return base64_encode(hash_hmac('sha1', $signed_data, $this->key, true));
    }
}
