<?php

/*
 * This file is part of the Sylius package.
 *
 * (c) Paweł Jędrzejewski
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AppBundle\Command\Installer;

use AppBundle\Entity\AdminUser;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Webmozart\Assert\Assert;

/**
 * @author Paweł Jędrzejewski <pawel@sylius.org>
 */
final class SetupCommand extends AbstractInstallCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('app:install:setup')
            ->setDescription('Sylius configuration setup.')
            ->setHelp(<<<EOT
The <info>%command.name%</info> command allows user to configure basic Sylius data.
EOT
            )
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->setupAdministratorUser($input, $output);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function setupAdministratorUser(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('Create your administrator account.');

        $userManager = $this->get('sylius.manager.admin_user');
        $userFactory = $this->get('sylius.factory.admin_user');

        try {
            $user = $this->configureNewUser($userFactory->createNew(), $input, $output);
        } catch (\InvalidArgumentException $exception) {
            return 0;
        }

        $user->setEnabled(true);

        $userManager->persist($user);
        $userManager->flush();

        $output->writeln('Administrator account successfully registered.');
    }

    /**
     * @param AdminUser $user
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return AdminUser
     */
    private function configureNewUser(AdminUser $user, InputInterface $input, OutputInterface $output)
    {
        $userRepository = $this->get('sylius.repository.admin_user');

        if ($input->getOption('no-interaction')) {
            Assert::notNull($userRepository->findOneByEmail('admin@example.com'));

            $user->setEmail('admin@example.com');
            $user->setPlainPassword('admin');

            return $user;
        }

        $questionHelper = $this->getHelper('question');

        do {
            $question = $this->createEmailQuestion($output);
            $email = $questionHelper->ask($input, $output, $question);
            $exists = null !== $userRepository->findOneByEmail($email);

            if ($exists) {
                $output->writeln('<error>E-Mail is already in use!</error>');
            }
        } while ($exists);

        $user->setEmail($email);
        $user->setPlainPassword($this->getAdministratorPassword($input, $output));

        return $user;
    }

    /**
     * @param OutputInterface $output
     *
     * @return Question
     */
    private function createEmailQuestion(OutputInterface $output)
    {
        return (new Question('E-mail:'))
            ->setValidator(function ($value) use ($output) {
                /** @var ConstraintViolationListInterface $errors */
                $errors = $this->get('validator')->validate((string) $value, [new Email(), new NotBlank()]);
                foreach ($errors as $error) {
                    throw new \DomainException($error->getMessage());
                }

                return $value;
            })
            ->setMaxAttempts(3)
            ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     *
     * @return mixed
     */
    private function getAdministratorPassword(InputInterface $input, OutputInterface $output)
    {
        /** @var QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        $validator = $this->getPasswordQuestionValidator($output);

        do {
            $passwordQuestion = $this->createPasswordQuestion('Choose password:', $validator);
            $confirmPasswordQuestion = $this->createPasswordQuestion('Confirm password:', $validator);

            $password = $questionHelper->ask($input, $output, $passwordQuestion);
            $repeatedPassword = $questionHelper->ask($input, $output, $confirmPasswordQuestion);

            if ($repeatedPassword !== $password) {
                $output->writeln('<error>Passwords do not match!</error>');
            }
        } while ($repeatedPassword !== $password);

        return $password;
    }

    /**
     * @param OutputInterface $output
     *
     * @return \Closure
     */
    private function getPasswordQuestionValidator(OutputInterface $output)
    {
        return function ($value) use ($output) {
            /** @var ConstraintViolationListInterface $errors */
            $errors = $this->get('validator')->validate($value, [new NotBlank()]);
            foreach ($errors as $error) {
                throw new \DomainException($error->getMessage());
            }

            return $value;
        };
    }

    /**
     * @param string $message
     * @param \Closure $validator
     *
     * @return Question
     */
    private function createPasswordQuestion($message, \Closure $validator)
    {
        return (new Question($message))
            ->setValidator($validator)
            ->setMaxAttempts(3)
            ->setHidden(true)
            ->setHiddenFallback(false)
            ;
    }
}