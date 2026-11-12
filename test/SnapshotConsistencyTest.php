<?php

namespace ParagonIE\CSPBuilderTest;

use ParagonIE\CSPBuilder\CSPBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that every configuration mutation invalidates the compiled
 * snapshot, so a direct compile(), the three header outputs and the saved
 * snippets always reflect the current policies.
 */
class SnapshotConsistencyTest extends TestCase
{
    /**
     * Loads the report-to fixture used by the report related tests.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getReportToFixture(): array
    {
        $data = \file_get_contents(__DIR__ . '/vectors/report-to.json');
        $this->assertIsString($data);
        $fixture = \json_decode($data, true);
        $this->assertIsArray($fixture);
        return $fixture;
    }

    /**
     * Every output path must share the same compiled snapshot, and each
     * consecutive mutation must invalidate the previously compiled header.
     */
    public function testCompiledSnapshotIsConsistentAcrossOutputsAndChanges(): void
    {
        $csp = new CSPBuilder();
        $csp->setSelfAllowed('default-src', true)
            ->addSource('img-src', 'https://cdn.example.com')
            ->disableOldBrowserSupport();

        $first = $csp->compile();
        $this->assertSame($first, $csp->getCompiledHeader());
        $this->assertSame($first, $csp->getHeaderArray(false)['Content-Security-Policy']);
        $this->assertSame(
            $first,
            $csp->injectCSPHeader(new MessageStub(), false)->getHeaderLine('Content-Security-Policy')
        );

        $csp->addSource('img-src', 'https://images.example.com');
        $second = $csp->compile();
        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('images.example.com', $second);
        $this->assertSame($second, $csp->getCompiledHeader());
        $this->assertSame($second, $csp->getHeaderArray(false)['Content-Security-Policy']);
        $this->assertSame(
            $second,
            $csp->injectCSPHeader(new MessageStub(), false)->getHeaderLine('Content-Security-Policy')
        );

        $csp->setAllowUnsafeInline('script-src', true);
        $third = $csp->compile();
        $this->assertNotSame($second, $third);
        $this->assertStringContainsString("script-src 'unsafe-inline'", $third);
        $this->assertSame($third, $csp->getCompiledHeader());
        $this->assertSame($third, $csp->getHeaderArray(false)['Content-Security-Policy']);

        $csp->setReportUri('https://report.example.com/csp')
            ->setReportTo('csp-endpoint');
        $fourth = $csp->compile();
        $this->assertNotSame($third, $fourth);
        $this->assertStringContainsString('report-uri https://report.example.com/csp', $fourth);
        $this->assertStringContainsString('report-to csp-endpoint', $fourth);
        $this->assertSame($fourth, $csp->getCompiledHeader());
        $this->assertSame($fourth, $csp->getHeaderArray(false)['Content-Security-Policy']);

        // Re-reading without any mutation must keep serving the same snapshot.
        $this->assertSame($fourth, $csp->compile());
        $this->assertSame($fourth, $csp->getCompiledHeader());
    }

    /**
     * Removing a directive must invalidate the header and the nginx/apache
     * snippets, which must no longer allow the removed source.
     */
    public function testRemovingDirectiveInvalidatesHeaderAndSnippets(): void
    {
        foreach ([CSPBuilder::FORMAT_NGINX, CSPBuilder::FORMAT_APACHE] as $format) {
            $csp = new CSPBuilder();
            $csp->addSource('img-src', 'https://cdn.example.com')
                ->addSource('style-src', 'https://css.example.com')
                ->disableOldBrowserSupport();

            $before = $csp->getCompiledHeader();
            $this->assertStringContainsString('cdn.example.com', $before);
            $this->assertStringContainsString('css.example.com', $before);

            $csp->removeDirective('img-src');

            $after = $csp->getCompiledHeader();
            $this->assertNotSame($before, $after);
            $this->assertStringNotContainsString('cdn.example.com', $after);
            $this->assertStringContainsString('css.example.com', $after);

            $tempfile = \tempnam(\sys_get_temp_dir(), 'csp');
            $this->assertNotFalse($tempfile);
            $this->assertTrue($csp->saveSnippet($tempfile, $format));
            $snippet = (string) \file_get_contents($tempfile);
            \unlink($tempfile);

            $this->assertStringContainsString('css.example.com', $snippet);
            $this->assertStringNotContainsString('cdn.example.com', $snippet);
        }
    }

    /**
     * Report endpoint and report-uri/report-to changes must invalidate both
     * the Report-To header and the CSP header, including after removal.
     */
    public function testReportChangesInvalidateCompiledSnapshots(): void
    {
        $fixture = $this->getReportToFixture();

        $csp = new CSPBuilder();
        $csp->setSelfAllowed('default-src', true)
            ->setReportEndpoints($fixture);

        $endpoints = $csp->getCompiledReportEndpointsHeader();
        $this->assertStringContainsString('csp-endpoint', $endpoints);
        $this->assertStringContainsString(
            'https:\/\/report.example.com\/csp',
            $endpoints
        );

        $headers = $csp->getHeaderArray(false);
        $this->assertSame($endpoints, $headers['Report-To']);

        // Replacing the endpoints must invalidate the cached Report-To header.
        $csp->setReportEndpoints([
            'group' => 'other-endpoint',
            'max_age' => 3600,
            'endpoints' => [
                ['url' => 'https://report.example.com/other']
            ]
        ]);
        $replaced = $csp->getCompiledReportEndpointsHeader();
        $this->assertStringNotContainsString('csp-endpoint', $replaced);
        $this->assertStringContainsString('other-endpoint', $replaced);
        $this->assertSame($replaced, $csp->getHeaderArray(false)['Report-To']);

        // Removing the remaining endpoint must empty the cached header.
        $csp->removeReportEndpoint('other-endpoint');
        $this->assertSame('', $csp->getCompiledReportEndpointsHeader());
        $this->assertArrayNotHasKey('Report-To', $csp->getHeaderArray(false));

        // Adding it back via the fixture must rebuild the header again.
        $csp->addReportEndpoints($fixture[0]);
        $readded = $csp->getCompiledReportEndpointsHeader();
        $this->assertStringContainsString('csp-endpoint', $readded);

        // report-uri/report-to directive changes must invalidate the CSP header.
        $before = $csp->getCompiledHeader();
        $csp->setReportUri('https://report.example.com/enforced');
        $afterUri = $csp->getCompiledHeader();
        $this->assertNotSame($before, $afterUri);
        $this->assertStringContainsString(
            'report-uri https://report.example.com/enforced',
            $afterUri
        );

        $csp->setReportTo('csp-endpoint');
        $afterTo = $csp->getCompiledHeader();
        $this->assertNotSame($afterUri, $afterTo);
        $this->assertStringContainsString('report-to csp-endpoint', $afterTo);
    }
}
