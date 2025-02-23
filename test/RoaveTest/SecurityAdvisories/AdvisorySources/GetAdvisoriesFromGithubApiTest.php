<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

declare(strict_types=1);

namespace RoaveTest\SecurityAdvisories\AdvisorySources;

use Http\Client\Curl\Client;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psl\Exception\InvariantViolationException;
use Psl\Json;
use Psl\Str;
use Psl\Type;
use Psl\Type\Exception\AssertException;
use Psl\Vec;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use ReflectionException;
use ReflectionMethod;
use Roave\SecurityAdvisories\Advisory;
use Roave\SecurityAdvisories\AdvisorySources\GetAdvisoriesFromGithubApi;
use UnexpectedValueException;

class GetAdvisoriesFromGithubApiTest extends TestCase
{
    public function testGithubAdvisoriesHasToken(): void
    {
        $client = $this->createMock(Client::class);
        $logger = $this->createMock(LoggerInterface::class);

        $this->expectException(InvariantViolationException::class);

        new GetAdvisoriesFromGithubApi($client, '', $logger);
    }

    /**
     * @throws ReflectionException
     *
     * @dataProvider cursorProvider
     */
    public function testGithubAdvisoriesQueryMethod(string $cursor, bool $shouldContainCursor): void
    {
        $client = $this->createMock(Client::class);
        $logger = $this->createMock(LoggerInterface::class);

        $githubAdvisories = new GetAdvisoriesFromGithubApi($client, 'token', $logger);

        $overlapsWithReflection = new ReflectionMethod($githubAdvisories, 'queryWithCursor');

        $jsonEncodedQuery = Type\string()->assert($overlapsWithReflection->invoke($githubAdvisories, $cursor));

        $decodedQuery = Json\typed($jsonEncodedQuery, Type\shape([
            'query' => Type\string(),
        ]));

        if ($shouldContainCursor) {
            self::assertStringContainsString(Str\format('after: "%s"', $cursor), $decodedQuery['query']);
        } else {
            self::assertStringNotContainsString('after: ""', $decodedQuery['query']);
        }
    }

    /**
     * @param ResponseInterface[] $apiResponses
     *
     * @throws ClientExceptionInterface
     *
     * @dataProvider correctResponsesSequenceDataProvider
     */
    public function testGithubAdvisoriesIsAbleToProduceAdvisories(array $apiResponses): void
    {
        $client = $this->createMock(Client::class);
        $logger = $this->createMock(LoggerInterface::class);

        $logger->expects(self::exactly(2))
            ->method('debug')
            ->with('Sending request for cursor {cursor}', self::arrayHasKey('cursor'));
        $client->expects(self::exactly(2))
            ->method('sendRequest')
            ->willReturnOnConsecutiveCalls(...$apiResponses);

        $advisories = new GetAdvisoriesFromGithubApi($client, 'some_token', $logger);

        self::assertEquals(
            [
                Advisory::fromArrayData([
                    'reference' => 'enshrined/svg-sanitize',
                    'branches' => [['versions' => ['> 0.12.0, < 0.12.1 ']]],
                ]),
                Advisory::fromArrayData([
                    'reference' => 'foo/bar',
                    'branches' => [['versions' => ['> 1.2.3, < 4.5.6 ']]],
                ]),
            ],
            Vec\values($advisories()),
        );
    }

    public function testWillLogErrorsForAdvisoriesWithInvalidVersionConstraints(): void
    {
        $client = $this->createMock(Client::class);
        $logger = $this->createMock(LoggerInterface::class);

        $client->method('sendRequest')
            ->willReturn(new Response(
                200,
                [],
                <<<'JSON'
                {
                  "data": {
                    "securityVulnerabilities": {
                      "edges": [
                        {
                          "cursor": "Y3Vyc29yOnYyOpK5MjAyMC0wMS0wOFQxOToxNTowNiswMjowMM0LdA==",
                          "node": {
                            "vulnerableVersionRange": "haha",
                            "package": {
                              "name": "enshrined/svg-sanitize"
                            },
                            "advisory": {
                              "ghsaId": "aaa-bbb",
                              "withdrawnAt": null
                            }
                          }
                        },
                        {
                          "cursor": "Y3Vyc29yOnYyOpK5MjAyMC0wMS0wOFQxOToxNTowNiswMjowMM0LdB==",
                          "node": {
                            "vulnerableVersionRange": "> 1.2.3, < 4.5.6 ",
                            "package": {
                              "name": "foo/bar"
                            },
                            "advisory": {
                              "ghsaId": "ccc-ddd",
                              "withdrawnAt": null
                            }
                          }
                        }
                      ],
                      "pageInfo": {
                        "hasNextPage": false,
                        "endCursor": null
                      }
                    }
                  }
                }
        JSON,
            ));

        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Error while processing advisory {githubSecurityAdvisoryId} for {package}: {exception}',
                self::callback(static function (array $context): bool {
                    self::assertArrayHasKey('githubSecurityAdvisoryId', $context);
                    self::assertArrayHasKey('package', $context);
                    self::assertArrayHasKey('exception', $context);
                    self::assertSame('aaa-bbb', $context['githubSecurityAdvisoryId']);
                    self::assertSame('enshrined/svg-sanitize', $context['package']);
                    self::assertInstanceOf(UnexpectedValueException::class, $context['exception']);

                    return true;
                }),
            );

        self::assertEquals(
            [
                Advisory::fromArrayData([
                    'reference' => 'foo/bar',
                    'branches' => [['versions' => ['> 1.2.3, < 4.5.6 ']]],
                ]),
            ],
            Vec\values((new GetAdvisoriesFromGithubApi($client, 'some_token', $logger))()),
        );
    }

    /**
     * @throws ClientExceptionInterface
     *
     * @dataProvider responsesWithIncorrectRangesProvider
     */
    public function testGithubAdvisoriesFailToCompileGettingIncorrectRanges(ResponseInterface $response): void
    {
        $client = $this->createMock(Client::class);
        $logger = $this->createMock(LoggerInterface::class);

        $client->expects(self::once())
            ->method('sendRequest')
            ->with(self::callback(static function (RequestInterface $request) {
                $headers = $request->getHeaders();

                self::assertArrayHasKey('Authorization', $headers);
                self::assertArrayHasKey('Content-Type', $headers);
                self::assertArrayHasKey('User-Agent', $headers);

                return true;
            }))
            ->willReturn($response);

        $this->expectException(AssertException::class);

        (new GetAdvisoriesFromGithubApi($client, 'some_token', $logger))()->next();
    }

    /** @psalm-return non-empty-list<array{list<ResponseInterface>}> */
    public function correctResponsesSequenceDataProvider(): array
    {
        $responseBodies = [
            <<<'F'
                {
                  "data": {
                    "securityVulnerabilities": {
                      "edges": [
                        {
                          "cursor": "Y3Vyc29yOnYyOpK5MjAyMC0wMS0wOFQxOToxNTowNiswMjowMM0LdA==",
                          "node": {
                            "vulnerableVersionRange": "> 0.12.0, < 0.12.1 ",
                            "package": {
                              "name": "enshrined/svg-sanitize"
                            },
                            "advisory": {
                              "ghsaId": "aaa-bbb",
                              "withdrawnAt": null
                            }
                          }
                        },
                        {
                          "cursor": "Y3Vyc29yOnYyOpK5MjAyMC0wMS0wOFQxOToxNTowNiswMjowMM0LdB==",
                          "node": {
                            "vulnerableVersionRange": "> 1.2.3, < 4.5.6 ",
                            "package": {
                              "name": "foo/bar"
                            },
                            "advisory": {
                              "ghsaId": "aaa-bbb",
                              "withdrawnAt": null
                            }
                          }
                        }
                      ],
                      "pageInfo": {
                        "hasNextPage": true,
                        "endCursor": "Y3Vyc29yOnYyOpK5MjAyMC0wMS0wOFQxOToxNTowNiswMjowMM0LdA=="
                      }
                    }
                  }
                }
            F,
            <<<'S'
                {
                  "data": {
                    "securityVulnerabilities": {
                      "edges": [],
                      "pageInfo": {
                        "hasNextPage": false,
                        "endCursor": null
                      }
                    }
                  }
                }
            S,
        ];

        $first  = new Response(200, [], $responseBodies[0]);
        $second = new Response(200, [], $responseBodies[1]);

        return [
            [
                [
                    $first,
                    $second,
                ],
            ],
        ];
    }

    /** @dataProvider correctResponsesWithInvalidAdvisoryNames */
    public function testWillSkipAdvisoriesWithMalformedNames(ResponseInterface ...$responses): void
    {
        $client = $this->createMock(Client::class);
        $logger = $this->createMock(LoggerInterface::class);

        $client->method('sendRequest')
            ->willReturnOnConsecutiveCalls(...$responses);

        $advisories = new GetAdvisoriesFromGithubApi($client, 'some_token', $logger);

        self::assertEquals(
            [
                Advisory::fromArrayData([
                    'reference' => 'aa/bb',
                    'branches' => [['versions' => ['> 0.12.0, < 0.12.1 ']]],
                ]),
                Advisory::fromArrayData([
                    'reference' => 'dd/ee',
                    'branches' => [['versions' => ['> 1.2.3, < 4.5.6 ']]],
                ]),
            ],
            Vec\values($advisories()),
        );
    }

    /**
     * @throws ClientExceptionInterface
     *
     * @dataProvider correctResponseWithWithdrawnAdvisories
     */
    public function testWillSkipWithdrawnAdvisories(ResponseInterface ...$responses): void
    {
        $client = $this->createMock(Client::class);
        $logger = $this->createMock(LoggerInterface::class);

        $client->method('sendRequest')
            ->willReturnOnConsecutiveCalls(...$responses);

        $advisories = new GetAdvisoriesFromGithubApi($client, 'some_token', $logger);

        self::assertEquals([
            Advisory::fromArrayData([
                'reference' => 'aa/bb',
                'branches' => [['versions' => ['<= 1.1.0']]],
            ]),
        ], Vec\Values($advisories()));
    }

    /** @dataProvider correctResponseWithIgnoredAdvisories */
    public function testWillSkipIgnoredAdvisories(ResponseInterface ...$responses): void
    {
        $client = $this->createMock(Client::class);
        $logger = $this->createMock(LoggerInterface::class);

        $client->method('sendRequest')
            ->willReturnOnConsecutiveCalls(...$responses);

        $advisories = new GetAdvisoriesFromGithubApi($client, 'some_token', $logger);

        self::assertEquals([
            Advisory::fromArrayData([
                'reference' => 'aa/bb',
                'branches' => [['versions' => ['<= 1.1.0']]],
            ]),
        ], Vec\Values($advisories()));
    }

    /** @psalm-return non-empty-list<list<ResponseInterface>> */
    public function correctResponsesWithInvalidAdvisoryNames(): array
    {
        $responseBodies = [
            <<<'F'
                {
                  "data": {
                    "securityVulnerabilities": {
                      "edges": [
                        {
                          "cursor": "Y3Vyc29yOnYyOpK5MjAyMC0wMS0wOFQxOToxNTowNiswMjowMM0LdA==",
                          "node": {
                            "vulnerableVersionRange": "> 0.12.0, < 0.12.1 ",
                            "package": {
                              "name": "aa/bb"
                            },
                            "advisory": {
                              "ghsaId": "aaa-bbb",
                              "withdrawnAt": null
                            }
                          }
                        },
                        {
                          "cursor": "Y3Vyc29yOnYyOpK5MjAyMC0wMS0wOFQxOToxNTowNiswMjowMM0LdB==",
                          "node": {
                            "vulnerableVersionRange": "> 0.12.0, < 0.12.1 ",
                            "package": {
                              "name": "cc"
                            },
                            "advisory": {
                              "ghsaId": "aaa-bbb",
                              "withdrawnAt": null
                            }
                          }
                        },
                        {
                          "cursor": "Y3Vyc29yOnYyOpK5MjAyMC0wMS0wOFQxOToxNTowNiswMjowMM0LdB==",
                          "node": {
                            "vulnerableVersionRange": "> 1.2.3, < 4.5.6 ",
                            "package": {
                              "name": "dd/ee"
                            },
                            "advisory": {
                              "ghsaId": "aaa-bbb",
                              "withdrawnAt": null
                            }
                          }
                        }
                      ],
                      "pageInfo": {
                        "hasNextPage": true,
                        "endCursor": "Y3Vyc29yOnYyOpK5MjAyMC0wMS0wOFQxOToxNTowNiswMjowMM0LdA=="
                      }
                    }
                  }
                }
            F,
            <<<'S'
                {
                  "data": {
                    "securityVulnerabilities": {
                      "edges": [],
                      "pageInfo": {
                        "hasNextPage": false,
                        "endCursor": null
                      }
                    }
                  }
                }
            S,
        ];

        $first  = new Response(200, [], $responseBodies[0]);
        $second = new Response(200, [], $responseBodies[1]);

        return [
            [
                $first,
                $second,
            ],
        ];
    }

    /** @psalm-return non-empty-list<array{ResponseInterface}> */
    public function responsesWithIncorrectRangesProvider(): array
    {
        $query = <<<'QUERY'
                {
                  "data": {
                    "securityVulnerabilities": {
                      "edges": [
                        {
                          "cursor": "Y3Vyc29yOnYyOpK5MjAyMC0wMS0wOFQxOToxNTowNiswMjowMM0LdA==",
                          "node": {
                            "vulnerableVersionRange": "%s",
                            "package": {
                              "name": "enshrined/svg-sanitize"
                            },
                            "advisory": {
                              "ghsaId": "aaa-bbb",
                              "withdrawnAt": null
                            }
                          }
                        }
                      ],
                      "pageInfo": {
                        "hasNextPage": false,
                        "endCursor": "Y3Vyc29yOnYyOpK5MjAyMC0wMS0wOFQxOToxNTowNiswMjowMM0LdA=="
                      }
                    }
                  }
                }
            QUERY;

        $incorrectRanges = [
            '',
            ',',
            '> 1,', // correct open constraint, but empty closing constraint
            'a,b,c', // we may have a max of 2 versions
        ];

        $responses = [];

        foreach ($incorrectRanges as $range) {
            $responses[] = [new Response(200, [], Str\format($query, $range))];
        }

        return $responses;
    }

    /** @psalm-return non-empty-list<list<ResponseInterface>> */
    public function correctResponseWithWithdrawnAdvisories(): array
    {
        $query = <<<'QUERY'
                {
                  "data": {
                    "securityVulnerabilities": {
                      "edges": [
                        {
                          "cursor": "Y3Vyc29yOnYyOpK5MjAyMS0xMS0xNVQyMzoyMDo0NyswMTowMM1Qsw==",
                          "node": {
                            "vulnerableVersionRange": "<= 2.0",
                            "package": {
                              "name": "aa/bb"
                            },
                            "advisory": {
                              "ghsaId": "aaa-bbb",
                              "withdrawnAt": "2021-11-17T15:54:51Z"
                            }
                          }
                        },
                        {
                          "cursor": "Y3Vyc29yOnYyOpK5MjAyMS0wNS0wNVQwMDo0Njo1MSswMjowMM0_Fg==",
                          "node": {
                            "vulnerableVersionRange": "<= 1.1.0",
                            "package": {
                              "name": "aa/bb"
                            },
                            "advisory": {
                              "ghsaId": "aaa-bbb",
                              "withdrawnAt": null
                            }
                          }
                        }
                      ],
                      "pageInfo": {
                        "hasNextPage": false,
                        "endCursor": "Y3Vyc29yOnYyOpK5MjAyMS0wNS0wNVQwMDo0Njo1MSswMjowMM0_Fg=="
                      }
                    }
                  }
                }
            QUERY;

        return [[new Response(200, [], $query)]];
    }

    /** @psalm-return non-empty-list<list<ResponseInterface>> */
    public function correctResponseWithIgnoredAdvisories(): array
    {
        $query = <<<'QUERY'
                {
                  "data": {
                    "securityVulnerabilities": {
                      "edges": [
                        {
                          "cursor": "Y3Vyc29yOnYyOpK5MjAyMS0wNS0wNVQwMDo0Njo1MSswMjowMM0_Fg==",
                          "node": {
                            "vulnerableVersionRange": "<= 1.1.0",
                            "package": {
                              "name": "aa/bb"
                            },
                            "advisory": {
                              "ghsaId": "aaa-bbb",
                              "withdrawnAt": null
                            }
                          }
                        },
                        {
                          "cursor": "Y3Vyc29yOnYyOpK5MjAyNC0xMi0wMlQyMToxOTo0MSswMTowMM3_Og==",
                          "node": {
                            "vulnerableVersionRange": "< 6.4.4",
                            "package": {
                              "name": "symfony/var-dumper"
                            },
                            "advisory": {
                              "ghsaId": "GHSA-cg28-v4wq-whv5",
                              "withdrawnAt": null
                            }
                          }
                        },
                        {
                          "cursor": "3Vyc29yOnYyOpK5MjAyNC0xMi0wMlQyMToyMToxMSswMTowMM3_Ow==",
                          "node": {
                            "vulnerableVersionRange": "< 7.1.0",
                            "package": {
                              "name": "symfony/security-http"
                            },
                            "advisory": {
                              "ghsaId": "GHSA-7q22-x757-cmgc",
                              "withdrawnAt": null
                            }
                          }
                        }
                      ],
                      "pageInfo": {
                        "hasNextPage": false,
                        "endCursor": "3Vyc29yOnYyOpK5MjAyNC0xMi0wMlQyMToyMToxMSswMTowMM3_Ow=="
                      }
                    }
                  }
                }
            QUERY;

        return [[new Response(200, [], $query)]];
    }

    /** @psalm-return non-empty-list<array{string, bool}> */
    public function cursorProvider(): array
    {
        return [
            [
                '',
                false,
            ],
            [
                'abc',
                true,
            ],
        ];
    }
}
