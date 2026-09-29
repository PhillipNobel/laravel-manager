<?php

namespace App\Support;

class DomainGenerator
{
    public static function generate(string $subdomain, string $baseDomain): string
    {
        $subdomain = strtolower(trim($subdomain));
        $baseDomain = strtolower(trim($baseDomain, ". \t\n\r\0\x0B"));

        return $subdomain.'.'.$baseDomain;
    }
}
