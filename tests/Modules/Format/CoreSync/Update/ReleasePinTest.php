<?php

namespace Tests\Modules\Format\CoreSync\Update;

use Okay\Modules\Format\CoreSync\Core\Update\ReleasePin;
use PHPUnit\Framework\TestCase;

/**
 * Пин канонического префикса релизов [SECURITY-SENSITIVE], threat-model §1: ядро называет url —
 * модуль качает ТОЛЬКО с канонического префикса. Чужой хост/схема/путь-обход → отказ.
 */
class ReleasePinTest extends TestCase
{
    private function pinnedUrl(string $tag = 'okay-v1.2.0'): string
    {
        return ReleasePin::RELEASE_PREFIX . $tag . '/' . $tag . '.tar.gz';
    }

    public function testCanonicalReleaseUrlIsPinned(): void
    {
        $this->assertTrue(ReleasePin::isPinned($this->pinnedUrl()));
    }

    /** ⚠ Зеркало контракта: значение обязано совпадать с ядром (SatelliteReleaseSettings). */
    public function testPrefixMatchesCoreContractLiteral(): void
    {
        $this->assertSame(
            'https://github.com/brightsf/coresync/releases/download/',
            ReleasePin::RELEASE_PREFIX,
            'префикс разошёлся с ядром — сателлиты перестанут принимать легитимные релизы'
        );
    }

    /**
     * @dataProvider foreignUrls
     */
    public function testForeignOrTamperedUrlsAreRejected(string $url, string $why): void
    {
        $this->assertFalse(ReleasePin::isPinned($url), $why);
    }

    /**
     * @return array<string, array{0:string,1:string}>
     */
    public function foreignUrls(): array
    {
        $p = ReleasePin::RELEASE_PREFIX;

        return [
            'чужой хост'                => ['https://evil.example/coresync/x.tar.gz', 'чужой домен'],
            'http вместо https'         => ['http://github.com/brightsf/coresync/releases/download/okay-v1.2.0/x.tar.gz', 'даунгрейд схемы'],
            'чужой репо на github'       => ['https://github.com/attacker/coresync/releases/download/x/x.tar.gz', 'чужой owner/repo'],
            'поддельный поддомен'        => ['https://github.com.evil.example/brightsf/coresync/releases/download/x/x.tar.gz', 'подделка хоста суффиксом'],
            'traversal в пути'          => [$p . '../../../../etc/passwd', 'обход выше по пути'],
            'traversal между сегментами' => [$p . 'okay-v1.2.0/../../evil/x.tar.gz', 'обход через .. в середине'],
            'пустой url'                => ['', 'пусто'],
            'CRLF-инъекция'             => [$p . "okay-v1.2.0/x.tar.gz\r\nHost: evil", 'управляющие символы'],
            'префикс как подстрока'      => ['https://mirror.example/?u=' . $p . 'okay-v1.2.0/x.tar.gz', 'префикс не в начале'],
        ];
    }
}
