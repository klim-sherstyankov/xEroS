<?php declare(strict_types=1);

namespace Blockchain;

use JetBrains\PhpStorm\Pure;
use function bin2hex;
use function hash;

/**
 * Class Pow
 * @package Blockchain
 */
class Pow
{
    /**
     * @param string $data
     * @return string
     */
    public function doubleSha256(string $data): string
    {
        return hash(
            algo: 'sha256',
            data: hash(
                algo: 'sha256',
                data: $data,
                binary: true
            ),
            binary: true,
        );
    }

    /**
     * @param string $hash
     * @param string $data
     * @param string $nonce
     * @return bool
     */
    #[Pure]
    public function verifyPow(string $hash, string $data, string $nonce): bool
    {
        return $hash === bin2hex(string: $this->doubleSha256(data: $data . $nonce));
    }

    /**
     * function for proof of work
     * @param string $data
     * @param string $nonce
     * @return string
     */
    #[Pure]
    public function calculate(string $data, string $nonce): string
    {
        return $this->doubleSha256(data: $data . $nonce);
    }
}
