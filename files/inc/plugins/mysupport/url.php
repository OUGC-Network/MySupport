<?php

declare(strict_types=1);

namespace MySupport\Core;

class Url
{
    private string $url;

    public function __construct(?string $url = null)
    {
        if ($url === null) {
            $this->url = 'showthread.php';
        } else {
            $this->url = $url;
        }
    }

    public function set_url(string $url): self
    {
        $this->url = $url;

        return $this;
    }

    public function get_url(): string
    {
        return $this->url;
    }

    public function build(array $url_parameters = []): string
    {
        if (($offset = strpos($this->get_url(), '#')) === false) {
            $offset = strlen($this->get_url());
        }

        return substr_replace(
            $this->get_url(),
            (str_contains($this->get_url(), '?') ? $separator = '&amp;' : '?') .
            http_build_query($url_parameters, '', '&amp;'),
            $offset,
            0
        );
    }

    public function build_absolute(array $url_params = []): string
    {
        global $mybb;

        return $mybb->settings['bburl'] . '/' . $this->build($url_params);
    }

}