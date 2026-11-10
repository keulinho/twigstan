<?php

declare(strict_types=1);

namespace TwigStan\Processing\Flattening;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use TwigStan\PHP\PrettyPrinter;
use TwigStan\PHP\StrictPhpParser;
use TwigStan\Processing\Compilation\CompilationResult;
use TwigStan\Processing\Compilation\CompilationResultCollection;
use TwigStan\Processing\Compilation\Parser\TwigNodeParser;
use TwigStan\Twig\Metadata\MetadataAnalyzer;
use TwigStan\Twig\Metadata\MetadataRegistry;
use TwigStan\Twig\Node\NodeFinder as TwigNodeFinder;
use TwigStan\Twig\TwigFileCanonicalizer;

final class TwigFlattenerTest extends TestCase
{
    public function testPreservationCallsCanBePrintedByAutomaticFixes(): void
    {
        $filesystem = new Filesystem();
        $directory = $filesystem->tempnam(sys_get_temp_dir(), 'twigstan-flattening-');
        $filesystem->remove($directory);
        $filesystem->mkdir($directory);

        try {
            $filesystem->dumpFile($directory . '/template.twig', '');
            $filesystem->dumpFile($directory . '/compiled.php', <<<'PHP'
                <?php
                class __TwigTemplate_Test {
                    public function doDisplay(array $context): void {
                        $context = ['known' => 1];
                    }
                }
                PHP);
            $twig = new Environment(new FilesystemLoader($directory));
            $flattener = new TwigFlattener(
                new PrettyPrinter(),
                $filesystem,
                new MetadataRegistry(new MetadataAnalyzer($twig, new TwigNodeParser($twig, new TwigFileCanonicalizer($twig)), new TwigNodeFinder())),
                new StrictPhpParser($filesystem),
            );
            $result = $flattener->flatten(new CompilationResultCollection(new CompilationResult($directory . '/template.twig', $directory . '/compiled.php')), $directory . '/flattening', 1);
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
            $nodes = $parser->parse($filesystem->readFile($result->getByTwigFileName($directory . '/template.twig')->phpFile));
            self::assertNotNull($nodes);
            self::assertCount(2, (new NodeFinder())->findInstanceOf($nodes, Node\Expr\FuncCall::class));

            // PHPStan clones the analyzed nodes when building an automatic fix.
            $newNodes = (new NodeTraverser(new CloningVisitor()))->traverse($nodes);
            $value = (new NodeFinder())->findFirstInstanceOf($newNodes, Node\Scalar\Int_::class);
            self::assertInstanceOf(Node\Scalar\Int_::class, $value);
            $value->value = 2;
            $fixed = (new PrettyPrinter())->printFormatPreserving($newNodes, $nodes, $parser->getTokens());
            self::assertStringContainsString("'known' => 2", $fixed);
            self::assertStringContainsString('twigstan_preserve_context', $fixed);
        } finally {
            $filesystem->remove($directory);
        }
    }
}
