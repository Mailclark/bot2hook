<?php

namespace Bot2Hook\Entity;

class Room implements \JsonSerializable
{
    /** @var string */
    public $latest = null;

    /** @var array */
    public $members = [];

    public function __construct(array $data)
    {
        $this->latest = isset($data['latest']) ? $data['latest'] : null;
        $this->members = isset($data['members']) ? $data['members'] : null;
    }

    public function jsonSerialize()
    {
        return [
            'latest' => $this->latest,
            'members' => $this->members,
        ];
    }
}
