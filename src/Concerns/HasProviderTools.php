<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

trait HasProviderTools
{
    /** @var array<int, string> */
    protected array $providerTools = [];

    /**
     * @param  array<int, string>  $tools
     */
    public function withProviderTools(array $tools): self
    {
        $this->providerTools = $tools;

        return $this;
    }
}
