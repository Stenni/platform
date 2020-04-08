<?php

namespace Oro\Bundle\EmailBundle\Tests\Unit\Workflow\Action;

use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\ORM\EntityNotFoundException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Oro\Bundle\EmailBundle\Entity\EmailUser;
use Oro\Bundle\EmailBundle\Entity\Repository\EmailTemplateRepository;
use Oro\Bundle\EmailBundle\Form\Model\Email;
use Oro\Bundle\EmailBundle\Mailer\Processor;
use Oro\Bundle\EmailBundle\Model\DTO\EmailAddressDTO;
use Oro\Bundle\EmailBundle\Model\DTO\LocalizedTemplateDTO;
use Oro\Bundle\EmailBundle\Model\EmailHolderInterface;
use Oro\Bundle\EmailBundle\Model\EmailTemplate;
use Oro\Bundle\EmailBundle\Model\EmailTemplateCriteria;
use Oro\Bundle\EmailBundle\Provider\LocalizedTemplateProvider;
use Oro\Bundle\EmailBundle\Tests\Unit\Fixtures\Entity\TestEmailOrigin;
use Oro\Bundle\EmailBundle\Tools\AggregatedEmailTemplatesSender;
use Oro\Bundle\EmailBundle\Tools\EmailAddressHelper;
use Oro\Bundle\EmailBundle\Tools\EmailOriginHelper;
use Oro\Bundle\EmailBundle\Workflow\Action\SendEmailTemplate;
use Oro\Bundle\EntityBundle\Provider\EntityNameResolver;
use Oro\Bundle\LocaleBundle\Model\FirstNameInterface;
use Oro\Bundle\NotificationBundle\Tests\Unit\Event\Handler\Stub\EmailHolderStub;
use Oro\Component\Action\Exception\InvalidParameterException;
use Oro\Component\ConfigExpression\ContextAccessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Exception\ValidatorException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class SendEmailTemplateTest extends AbstractSendEmailTemplateTest
{
    /** @var AggregatedEmailTemplatesSender|\PHPUnit\Framework\MockObject\MockObject */
    private $sender;

    /** @var SendEmailTemplate */
    private $action;

    protected function setUp(): void
    {
        $this->contextAccessor = $this->createMock(ContextAccessor::class);
        $this->contextAccessor->expects($this->any())
            ->method('getValue')
            ->willReturnArgument(1);

        $this->emailProcessor = $this->createMock(Processor::class);
        $this->entityNameResolver = $this->createMock(EntityNameResolver::class);
        $this->registry = $this->createMock(ManagerRegistry::class);
        $this->validator = $this->createMock(ValidatorInterface::class);
        $this->localizedTemplateProvider = $this->createMock(LocalizedTemplateProvider::class);
        $this->emailOriginHelper = $this->createMock(EmailOriginHelper::class);

        $this->sender = $this->createMock(AggregatedEmailTemplatesSender::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->dispatcher = $this->createMock(EventDispatcher::class);

        $this->objectManager = $this->createMock(ObjectManager::class);
        $this->objectRepository = $this->createMock(EmailTemplateRepository::class);
        $this->emailTemplate = $this->createMock(EmailTemplate::class);

        $this->action = new SendEmailTemplate(
            $this->contextAccessor,
            $this->emailProcessor,
            new EmailAddressHelper(),
            $this->entityNameResolver,
            $this->registry,
            $this->validator,
            $this->localizedTemplateProvider,
            $this->emailOriginHelper
        );

        $this->action->setLogger($this->logger);
        $this->action->setDispatcher($this->dispatcher);

        $this->registry->expects($this->any())
            ->method('getManagerForClass')
            ->willReturn($this->objectManager);

        $this->objectManager
            ->method('getRepository')
            ->willReturn($this->objectRepository);

        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata->expects($this->any())
            ->method('getName')
            ->willReturn(\stdClass::class);

        $this->objectManager->expects($this->any())
            ->method('getClassMetadata')
            ->with(\stdClass::class)
            ->willReturn($classMetadata);
    }

    /**
     * @param array $options
     * @param string $exceptionName
     * @param string $exceptionMessage
     * @dataProvider initializeExceptionDataProvider
     */
    public function testInitializeException(array $options, $exceptionName, $exceptionMessage): void
    {
        $this->expectException($exceptionName);
        $this->expectExceptionMessage($exceptionMessage);
        $this->action->initialize($options);
    }

    /**
     * @return array
     */
    public function initializeExceptionDataProvider(): array
    {
        return [
            'no from' => [
                'options' => ['to' => 'test@test.com', 'template' => 'test', 'entity' => new \stdClass()],
                'exceptionName' => InvalidParameterException::class,
                'exceptionMessage' => 'From parameter is required',
            ],
            'no from email' => [
                'options' => [
                    'to' => 'test@test.com',
                    'template' => 'test',
                    'entity' => new \stdClass(),
                    'from' => ['name' => 'Test'],
                ],
                'exceptionName' => InvalidParameterException::class,
                'exceptionMessage' => 'Email parameter is required',
            ],
            'no to or recipients' => [
                'options' => ['from' => 'test@test.com', 'template' => 'test', 'entity' => new \stdClass()],
                'exceptionName' => InvalidParameterException::class,
                'exceptionMessage' => 'Need to specify "to" or "recipients" parameters',
            ],
            'no to email' => [
                'options' => [
                    'from' => 'test@test.com',
                    'template' => 'test',
                    'entity' => new \stdClass(),
                    'to' => ['name' => 'Test'],
                ],
                'exceptionName' => InvalidParameterException::class,
                'exceptionMessage' => 'Email parameter is required',
            ],
            'recipients in not an array' => [
                'options' => [
                    'from' => 'test@test.com',
                    'template' => 'test',
                    'entity' => new \stdClass(),
                    'recipients' => 'some@recipient.com',
                ],
                'exceptionName' => InvalidParameterException::class,
                'exceptionMessage' => 'Recipients parameter must be an array',
            ],
            'no to email in one of addresses' => [
                'options' => [
                    'from' => 'test@test.com',
                    'template' => 'test',
                    'entity' => new \stdClass(),
                    'to' => ['test@test.com', ['name' => 'Test']],
                ],
                'exceptionName' => InvalidParameterException::class,
                'exceptionMessage' => 'Email parameter is required',
            ],
            'no template' => [
                'options' => ['from' => 'test@test.com', 'to' => 'test@test.com', 'entity' => new \stdClass()],
                'exceptionName' => InvalidParameterException::class,
                'exceptionMessage' => 'Template parameter is required',
            ],
            'no entity' => [
                'options' => ['from' => 'test@test.com', 'to' => 'test@test.com', 'template' => 'test'],
                'exceptionName' => InvalidParameterException::class,
                'exceptionMessage' => 'Entity parameter is required',
            ],
        ];
    }

    /**
     * @dataProvider optionsDataProvider
     * @param array $options
     * @param array $expected
     */
    public function testInitialize($options, $expected): void
    {
        $this->assertSame($this->action, $this->action->initialize($options));
        $this->assertAttributeEquals($expected, 'options', $this->action);
    }

    /**
     * @return array
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    public function optionsDataProvider(): array
    {
        return [
            'simple' => [
                [
                    'from' => 'test@test.com',
                    'to' => 'test@test.com',
                    'template' => 'test',
                    'entity' => new \stdClass(),
                ],
                [
                    'from' => 'test@test.com',
                    'to' => ['test@test.com'],
                    'template' => 'test',
                    'entity' => new \stdClass(),
                    'recipients' => [],
                ],
            ],
            'simple with name' => [
                [
                    'from' => 'Test <test@test.com>',
                    'to' => 'Test <test@test.com>',
                    'template' => 'test',
                    'entity' => new \stdClass(),
                ],
                [
                    'from' => 'Test <test@test.com>',
                    'to' => ['Test <test@test.com>'],
                    'template' => 'test',
                    'entity' => new \stdClass(),
                    'recipients' => [],
                ],
            ],
            'extended' => [
                [
                    'from' => [
                        'name' => 'Test',
                        'email' => 'test@test.com',
                    ],
                    'to' => [
                        'name' => 'Test',
                        'email' => 'test@test.com',
                    ],
                    'template' => 'test',
                    'entity' => new \stdClass(),
                ],
                [
                    'from' => [
                        'name' => 'Test',
                        'email' => 'test@test.com',
                    ],
                    'to' => [
                        [
                            'name' => 'Test',
                            'email' => 'test@test.com',
                        ],
                    ],
                    'template' => 'test',
                    'entity' => new \stdClass(),
                    'recipients' => [],
                ],
            ],
            'multiple to' => [
                [
                    'from' => [
                        'name' => 'Test',
                        'email' => 'test@test.com',
                    ],
                    'to' => [
                        [
                            'name' => 'Test',
                            'email' => 'test@test.com',
                        ],
                        'test@test.com',
                        'Test <test@test.com>',
                    ],
                    'template' => 'test',
                    'entity' => new \stdClass(),
                ],
                [
                    'from' => [
                        'name' => 'Test',
                        'email' => 'test@test.com',
                    ],
                    'to' => [
                        [
                            'name' => 'Test',
                            'email' => 'test@test.com',
                        ],
                        'test@test.com',
                        'Test <test@test.com>',
                    ],
                    'template' => 'test',
                    'entity' => new \stdClass(),
                    'recipients' => [],
                ],
            ],
            'with recipients' => [
                [
                    'from' => 'test@test.com',
                    'to' => 'test2@test.com',
                    'recipients' => [new EmailHolderStub()],
                    'template' => 'test',
                    'entity' => new \stdClass(),
                ],
                [
                    'from' => 'test@test.com',
                    'to' => ['test2@test.com'],
                    'recipients' => [new EmailHolderStub()],
                    'template' => 'test',
                    'entity' => new \stdClass(),
                ],
            ],
        ];
    }

    /**
     * Test with expected \Doctrine\ORM\EntityNotFoundException for the case, when template does not found
     *
     * @expectedException \Doctrine\ORM\EntityNotFoundException
     */
    public function testExecuteWithoutTemplateEntity(): void
    {
        $this->localizedTemplateProvider->expects($this->once())
            ->method('getAggregated')
            ->willThrowException(new EntityNotFoundException());

        $this->emailProcessor->expects($this->never())
            ->method('process');

        $this->action->initialize(
            [
                'from' => 'test@test.com',
                'to' => 'test@test.com',
                'template' => 'test',
                'subject' => 'subject',
                'body' => 'body',
                'entity' => new \stdClass(),
            ]
        );
        $this->action->execute([]);
    }

    /**
     * @param string $violationMessage
     */
    private function mockValidatorViolations(string $violationMessage)
    {
        $violationList = $this->createMock(ConstraintViolationListInterface::class);
        $violation = $this->createMock(ConstraintViolationInterface::class);
        $violation->expects($this->once())
            ->method('getMessage')
            ->willReturn($violationMessage);
        $violationList->expects($this->once())
            ->method('get')
            ->willReturn($violation);

        $violationList->expects($this->once())
            ->method('count')
            ->willReturn(1);

        $this->validator->expects($this->once())
            ->method('validate')
            ->willReturn($violationList);
    }

    public function testExecuteWithInvalidEmail()
    {
        $this->localizedTemplateProvider->expects($this->never())
            ->method($this->anything());

        $this->emailProcessor->expects($this->never())
            ->method($this->anything());

        $this->action->initialize(
            [
                'from' => 'invalidemailaddress',
                'to' => 'test@test.com',
                'template' => 'test',
                'subject' => 'subject',
                'body' => 'body',
                'entity' => new \stdClass(),
            ]
        );

        $this->mockValidatorViolations('violation');

        $this->expectException(ValidatorException::class);
        $this->expectExceptionMessage("Validating \"From\" email (invalidemailaddress):\nviolation");

        $this->action->execute([]);
    }

    public function testExecuteWithProcessException(): void
    {
        $rcpt = new EmailAddressDTO('test@test.com');

        $dto = new LocalizedTemplateDTO($this->emailTemplate);
        $dto->addRecipient($rcpt);

        $this->localizedTemplateProvider->expects($this->once())
            ->method('getAggregated')
            ->with([$rcpt], new EmailTemplateCriteria('test', \stdClass::class), ['entity' => new \stdClass()])
            ->willReturn([$dto]);

        $this->emailTemplate->expects($this->once())
            ->method('getType')
            ->willReturn('plain/text');

        $emailOrigin = new TestEmailOrigin();
        $this->emailOriginHelper->expects($this->once())
            ->method('getEmailOrigin')
            ->with('test@test.com', null)
            ->willReturn($emailOrigin);

        $this->emailProcessor->expects($this->once())
            ->method('process')
            ->with($this->isInstanceOf('Oro\Bundle\EmailBundle\Form\Model\Email'))
            ->willThrowException(new \Swift_SwiftException('The email was not delivered.'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Workflow send email template action.');

        $this->action->initialize(
            [
                'from' => 'test@test.com',
                'to' => 'test@test.com',
                'template' => 'test',
                'subject' => 'subject',
                'body' => 'body',
                'entity' => new \stdClass(),
            ]
        );
        $this->action->execute([]);
    }

    /**
     * @dataProvider executeOptionsDataProvider
     *
     * @param array $options
     * @param string|object $recipient
     * @param array $expected
     */
    public function testExecuteWhenNoSender(array $options, $recipient, array $expected): void
    {
        $context = [];

        $this->entityNameResolver->expects($this->any())
            ->method('getName')
            ->willReturnCallback(
                static function () {
                    return '_Formatted';
                }
            );

        if (!$recipient instanceof EmailHolderInterface) {
            $recipient = new EmailAddressDTO($recipient);
        }

        $dto = new LocalizedTemplateDTO($this->emailTemplate);
        $dto->addRecipient(is_object($recipient) ? $recipient : new EmailAddressDTO($recipient));

        $this->localizedTemplateProvider->expects($this->once())
            ->method('getAggregated')
            ->with([$recipient], new EmailTemplateCriteria('test', \stdClass::class), ['entity' => new \stdClass()])
            ->willReturn([$dto]);

        $this->emailTemplate->expects($this->once())
            ->method('getType')
            ->willReturn('plain/text');
        $this->emailTemplate->expects($this->once())
            ->method('getSubject')
            ->willReturn($expected['subject']);
        $this->emailTemplate->expects($this->once())
            ->method('getContent')
            ->willReturn($expected['body']);

        $emailEntity = $this->createMock(Email::class);

        $emailUserEntity = $this->createMock(EmailUser::class);
        $emailUserEntity->expects($this->any())
            ->method('getEmail')
            ->willReturn($emailEntity);

        $emailOrigin = new TestEmailOrigin();
        $this->emailOriginHelper->expects($this->once())
            ->method('getEmailOrigin')
            ->with($expected['from'], null)
            ->willReturn($emailOrigin);

        $this->emailProcessor->expects($this->once())
            ->method('process')
            ->with(
                (new Email())
                    ->setFrom($expected['from'])
                    ->setSubject($expected['subject'])
                    ->setBody($expected['body'])
                    ->setTo($expected['to'])
                    ->setType('text'),
                $emailOrigin
            )
            ->willReturn($emailUserEntity);

        if (array_key_exists('attribute', $options)) {
            $this->contextAccessor->expects($this->once())
                ->method('setValue')
                ->with($context, $options['attribute'], $emailEntity);
        }

        $this->action->initialize($options);
        $this->action->execute($context);
    }

    /**
     * @dataProvider executeOptionsDataProvider
     *
     * @param array $options
     * @param string|object $recipient
     * @param array $expected
     */
    public function testExecute(array $options, $recipient, array $expected): void
    {
        $context = [];

        $this->entityNameResolver->expects($this->any())
            ->method('getName')
            ->willReturnCallback(
                static function () {
                    return '_Formatted';
                }
            );

        if (!$recipient instanceof EmailHolderInterface) {
            $recipient = new EmailAddressDTO($recipient);
        }

        $emailEntity = $this->createMock(Email::class);

        $emailUserEntity = $this->createMock(EmailUser::class);
        $emailUserEntity->expects($this->any())
            ->method('getEmail')
            ->willReturn($emailEntity);

        $this->sender->expects($this->once())
            ->method('send')
            ->with(new \stdClass(), [$recipient], $expected['from'], 'test')
            ->willReturn([$emailUserEntity]);

        if (array_key_exists('attribute', $options)) {
            $this->contextAccessor->expects($this->once())
                ->method('setValue')
                ->with($context, $options['attribute'], $emailEntity);
        }

        $this->action->setSender($this->sender);
        $this->action->initialize($options);
        $this->action->execute($context);
    }

    /**
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     *
     * @return array
     */
    public function executeOptionsDataProvider(): array
    {
        $nameMock = $this->createMock(FirstNameInterface::class);
        $nameMock->expects($this->any())
            ->method('getFirstName')
            ->willReturn('NAME');
        $recipient = new EmailHolderStub('recipient@test.com');

        return [
            'simple' => [
                [
                    'from' => 'test@test.com',
                    'to' => 'test@test.com',
                    'template' => 'test',
                    'entity' => new \stdClass(),
                ],
                'test@test.com',
                [
                    'from' => 'test@test.com',
                    'to' => ['test@test.com'],
                    'subject' => 'Test subject',
                    'body' => 'Test body',
                ],
                'de',
            ],
            'simple with name' => [
                [
                    'from' => '"Test" <test@test.com>',
                    'to' => '"Test" <test@test.com>',
                    'template' => 'test',
                    'entity' => new \stdClass(),
                ],
                '"Test" <test@test.com>',
                [
                    'from' => '"Test" <test@test.com>',
                    'to' => ['"Test" <test@test.com>'],
                    'subject' => 'Test subject',
                    'body' => 'Test body',
                ],
                'de',
            ],
            'extended' => [
                [
                    'from' => [
                        'name' => 'Test',
                        'email' => 'test@test.com',
                    ],
                    'to' => [
                        'name' => 'Test',
                        'email' => 'test@test.com',
                    ],
                    'template' => 'test',
                    'entity' => new \stdClass(),
                ],
                '"Test" <test@test.com>',
                [
                    'from' => '"Test" <test@test.com>',
                    'to' => ['"Test" <test@test.com>'],
                    'subject' => 'Test subject',
                    'body' => 'Test body',
                ],
                'de',
            ],
            'extended with name formatting' => [
                [
                    'from' => [
                        'name' => $nameMock,
                        'email' => 'test@test.com',
                    ],
                    'to' => [
                        'name' => $nameMock,
                        'email' => 'test@test.com',
                    ],
                    'template' => 'test',
                    'entity' => new \stdClass(),
                ],
                '"_Formatted" <test@test.com>',
                [
                    'from' => '"_Formatted" <test@test.com>',
                    'to' => ['"_Formatted" <test@test.com>'],
                    'subject' => 'Test subject',
                    'body' => 'Test body',
                ],
                'de',
            ],
            'with recipients' => [
                [
                    'from' => 'test@test.com',
                    'recipients' => [$recipient],
                    'template' => 'test',
                    'entity' => new \stdClass(),
                ],
                $recipient,
                [
                    'from' => 'test@test.com',
                    'to' => ['recipient@test.com'],
                    'subject' => 'Test subject',
                    'body' => 'Test body',
                ],
                'de',
            ],
        ];
    }
}
