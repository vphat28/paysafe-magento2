<?php

namespace Paysafe\Payment\Model;

class DataProvider
{
    private $data = [];

    public function setAdditionalData($key, $value)
    {
        $this->data[$key] = $value;
    }

    public function getAdditionalData($key)
    {
        if (isset($this->data[$key])) {
            return $this->data[$key];
        }

        return null;
    }
}