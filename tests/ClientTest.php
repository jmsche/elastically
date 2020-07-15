<?php

declare(strict_types=1);

namespace JoliCode\Elastically\Tests;

use Elastica\Exception\RuntimeException;
use JoliCode\Elastically\Client;

final class ClientTest extends BaseTestCase
{
    public function testClientIndexNameFromClass(): void
    {
        $this->expectException(RuntimeException::class);

        $client = new Client([
            Client::CONFIG_INDEX_CLASS_MAPPING => [
                'todo' => TestDTO::class,
            ],
        ]);

        $client->getIndexNameFromClass('OLA');
    }

    public function testIndexNameFromClassWithBackslashes(): void
    {
        $this->expectException(RuntimeException::class);

        $client = new Client([
            Client::CONFIG_INDEX_CLASS_MAPPING => [
                'todo' => '\\'.TestDTO::class,
            ],
        ]);

        $client->getIndexNameFromClass(TestDTO::class);
    }
}