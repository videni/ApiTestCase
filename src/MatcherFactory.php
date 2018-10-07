<?php

declare(strict_types=1);

/*
 * This file is part of the ApiTestCase package.
 *
 * (c) Łukasz Chruściel
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace ApiTestCase;

use Coduo\PHPMatcher\Lexer;
use Coduo\PHPMatcher\Matcher;
use Coduo\PHPMatcher\Parser;

class MatcherFactory
{
    public static function buildXmlMatcher(): Matcher
    {
        return self::buildMatcher(Matcher\XmlMatcher::class);
    }

    public static function buildJsonMatcher(): Matcher
    {
        return self::buildMatcher(Matcher\JsonMatcher::class);
    }

    protected static function buildMatcher(string $matcherClass): Matcher
    {
        $orMatcher = self::buildOrMatcher();
        $chainMatcher = new Matcher\ChainMatcher([
            new $matcherClass($orMatcher),
        ]);

        return new Matcher($chainMatcher);
    }

    protected static function buildOrMatcher(): Matcher\ChainMatcher
    {
        $scalarMatchers = self::buildScalarMatchers();
        $orMatcher = new Matcher\OrMatcher($scalarMatchers);
        $arrayMatcher = new Matcher\ArrayMatcher(
            new Matcher\ChainMatcher([
                $orMatcher,
                $scalarMatchers,
            ]),
            self::buildParser()
        );

        return new Matcher\ChainMatcher([
            $orMatcher,
            $arrayMatcher,
        ]);
    }

    protected static function buildScalarMatchers(): Matcher\ChainMatcher
    {
        $parser = self::buildParser();

        return new Matcher\ChainMatcher([
            new Matcher\CallbackMatcher(),
            new Matcher\ExpressionMatcher(),
            new Matcher\NullMatcher(),
            new Matcher\StringMatcher($parser),
            new Matcher\IntegerMatcher($parser),
            new Matcher\BooleanMatcher($parser),
            new Matcher\DoubleMatcher($parser),
            new Matcher\NumberMatcher($parser),
            new Matcher\ScalarMatcher(),
            new Matcher\WildcardMatcher(),
        ]);
    }

    protected static function buildParser(): Parser
    {
        return new Parser(new Lexer(), new Parser\ExpanderInitializer());
    }
}
