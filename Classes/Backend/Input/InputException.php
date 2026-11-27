<?php

declare(strict_types=1);

namespace ScaleCommerce\VideoOptimizer\Backend\Input;

/**
 * Invalid editor input; carries the label key (locallang_mod.xlf) of the message to show.
 */
final class InputException extends \InvalidArgumentException
{
    /**
     * @param list<string|int> $arguments sprintf arguments of the label
     */
    public function __construct(public readonly string $labelKey, public readonly array $arguments = [])
    {
        parent::__construct($labelKey);
    }
}
