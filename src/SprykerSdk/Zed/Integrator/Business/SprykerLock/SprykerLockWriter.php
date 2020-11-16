<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types = 1);

namespace SprykerSdk\Zed\Integrator\Business\SprykerLock;

use SprykerSdk\Zed\Integrator\IntegratorConfig;

class SprykerLockWriter
{
    protected const REPLACE_4_WITH_2_SPACES = '/^(  +?)\\1(?=[^ ])/m';

    /**
     * @var \SprykerSdk\Zed\Integrator\IntegratorConfig
     */
    protected $config;

    /**
     * @param \SprykerSdk\Zed\Integrator\IntegratorConfig $config
     */
    public function __construct(IntegratorConfig $config)
    {
        $this->config = $config;
    }

    /**
     * @param array $lockData
     *
     * @return int
     */
    public function storeLock(array $lockData): int
    {
        $lockFilePath = $this->config->getLockFilePath();
        $json = json_encode($lockData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $json = preg_replace(static::REPLACE_4_WITH_2_SPACES, '$1', $json) . PHP_EOL;
        if (file_put_contents($lockFilePath, $json) === false) {
            return 1;
        }

        return 0;
    }
}
