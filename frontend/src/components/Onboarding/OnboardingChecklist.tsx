import { useNavigate } from 'react-router-dom'
import { CheckCircleFilled, CloseOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { IconButton } from '@components/FieldHelp'
import config from '@config/config'
import useMailSettings from '@pages/Connections/data/useMailSettings'
import { type MailSettings } from '@pages/Connections/types'
import { Button, Card, Flex, Progress, Typography, theme } from 'antd'
import { type OnboardingFlags, useOnboardingFlags } from './onboardingStorage'

const { Text, Title } = Typography

/** One first-run setup step with its completion state and call to action. */
export interface OnboardingStep {
  key: string
  title: string
  description: string
  done: boolean
  optional: boolean
  cta: { label: string; to: string }
}

/** Whether the failure-alerts master switch is on in the stored mail settings. */
function alertsEnabled(settings: MailSettings): boolean {
  const { alerts } = settings.features ?? {}
  return !Array.isArray(alerts) && alerts?.enabled === true
}

/** The three setup steps, marked done from mail settings, the stored onboarding flags and the server's test-send record. */
export function onboardingSteps(
  settings: MailSettings,
  flags: OnboardingFlags,
  testSentOnServer = false
): OnboardingStep[] {
  return [
    {
      key: 'connection',
      title: __('Add a connection'),
      description: __('Choose the email service your site will send mail through.'),
      done: settings.connections.length > 0,
      optional: false,
      cta: { label: __('Add connection'), to: '/connection/new' }
    },
    {
      key: 'test',
      title: __('Send a test email'),
      description: __('Send yourself an email to check that mail really arrives.'),
      done: flags.testEmailSent || testSentOnServer,
      optional: false,
      cta: { label: __('Send test email'), to: '/test-mail' }
    },
    {
      key: 'alerts',
      title: __('Turn on alerts (optional)'),
      description: __('Get a message when your site stops sending email.'),
      done: alertsEnabled(settings),
      optional: true,
      cta: { label: __('Set up alerts'), to: '/settings?tab=notifications' }
    }
  ]
}

/** Whether every required step is complete, so the checklist no longer needs to show. */
export function isSetupComplete(steps: OnboardingStep[]): boolean {
  return steps.every(step => step.optional || step.done)
}

/** Render one checklist row: status icon, copy, and a CTA while the step is still open. */
function StepRow({ step, index }: { step: OnboardingStep; index: number }) {
  const { token } = theme.useToken()
  const navigate = useNavigate()
  return (
    <Flex component="li" gap="middle" align="flex-start" wrap>
      {step.done ? (
        <CheckCircleFilled
          role="img"
          aria-label={__('Done')}
          style={{ color: token.colorSuccess, fontSize: token.fontSizeLG, marginTop: 2 }}
        />
      ) : (
        <Text type="secondary" aria-hidden style={{ minWidth: token.fontSizeLG, textAlign: 'center' }}>
          {index + 1}
        </Text>
      )}
      <Flex vertical flex={1} style={{ minWidth: 200 }}>
        <Text strong type={step.done ? 'secondary' : undefined}>
          {step.title}
        </Text>
        <Text type="secondary">{step.description}</Text>
      </Flex>
      {step.done ? null : (
        <Button
          size="small"
          type={step.optional ? 'default' : 'primary'}
          onClick={() => navigate(step.cta.to)}
        >
          {step.cta.label}
        </Button>
      )}
    </Flex>
  )
}

/** First-run setup checklist; hidden once required steps are done or the admin dismisses it. */
export default function OnboardingChecklist() {
  const { token } = theme.useToken()
  const { data: settings } = useMailSettings()
  const [flags, updateFlags] = useOnboardingFlags()

  if (!settings || flags.checklistDismissed) {
    return null
  }

  const steps = onboardingSteps(settings, flags, config.TEST_EMAIL_SENT)
  if (isSetupComplete(steps)) {
    return null
  }

  const doneCount = steps.filter(step => step.done).length

  return (
    <Card
      role="region"
      aria-label={__('Get started with Bit SMTP')}
      style={{ margin: `${token.marginLG}px ${token.marginLG}px 0` }}
      title={
        <Title level={5} style={{ margin: 0 }}>
          {__('Get started with Bit SMTP')}
        </Title>
      }
      extra={
        <IconButton
          type="text"
          icon={<CloseOutlined />}
          label={__('Dismiss setup checklist')}
          onClick={() => updateFlags({ checklistDismissed: true })}
        />
      }
    >
      <Flex vertical gap="middle">
        <Progress
          percent={Math.round((doneCount / steps.length) * 100)}
          format={() => `${doneCount}/${steps.length}`}
          aria-label={__('Setup progress')}
        />
        <Flex component="ol" vertical gap="middle" style={{ margin: 0, padding: 0, listStyle: 'none' }}>
          {steps.map((step, index) => (
            <StepRow key={step.key} step={step} index={index} />
          ))}
        </Flex>
      </Flex>
    </Card>
  )
}
