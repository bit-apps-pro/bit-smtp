import { useState } from 'react'
import { deliveryStatusEntry } from '@common/glossary'
import { __, sprintf } from '@common/helpers/i18nwrap'
import request from '@common/helpers/request'
import notify from '@components/Toaster/Toaster'
import config from '@config/config'
import {
  type Connection,
  type FieldMeta,
  type ProviderMeta,
  type WebhookProvisioning
} from '@pages/Connections/types'
import { Alert, Button, Typography, theme } from 'antd'
import ProviderFields from './ProviderFields'

const { Text, Paragraph } = Typography

/** Tells the user what to do with the webhook URL, given how (and whether) it was set up for them. */
function setupInstruction(provider: ProviderMeta, provisioning: WebhookProvisioning | null | undefined) {
  if (provisioning?.status === 'registered') {
    return sprintf(__('Bit SMTP added this address to %s for you. Nothing else to do.'), provider.label)
  }

  if (provider.key === 'amazon_ses') {
    return __('In Amazon SNS, subscribe a topic for bounces, complaints and deliveries to this address.')
  }

  return sprintf(__('Add this address as a webhook in your %s dashboard.'), provider.label)
}

/** Renders webhook health: set-up outcome, whether updates arrive, and when the last one came in. */
function WebhookHealth({
  provisioning,
  verified,
  lastEventAt,
  providerLabel
}: {
  provisioning: WebhookProvisioning | null | undefined
  verified: boolean
  lastEventAt: string | undefined
  providerLabel: string
}) {
  const { token } = theme.useToken()

  switch (provisioning?.status) {
    case 'registered':
      return verified ? (
        <>
          <Text style={{ display: 'block', color: token.colorSuccessText }}>
            {__('✓ Working — delivery updates are arriving')}
          </Text>
          {lastEventAt ? (
            <Text type="secondary" style={{ display: 'block', fontSize: token.fontSizeSM }}>
              {sprintf(__('Last update: %s'), lastEventAt)}
            </Text>
          ) : null}
        </>
      ) : (
        <Text style={{ color: token.colorWarningText }}>
          {__('Set up — waiting for the first delivery update')}
        </Text>
      )
    case 'failed':
      return (
        <Alert
          type="warning"
          showIcon
          message={__("Couldn't set up delivery tracking automatically")}
          description={`${provisioning.reason ?? __('Unknown error')} — ${__(
            "Check your API key's permissions, then save again."
          )}`}
        />
      )
    case 'unsupported':
      return (
        <Alert
          type="info"
          showIcon
          message={sprintf(__('Set this up yourself in %s'), providerLabel)}
          description={__(
            'Bit SMTP can’t add the webhook for you here. Copy the address above into your provider.'
          )}
        />
      )
    case 'unavailable':
      return (
        <Alert
          type="info"
          showIcon
          message={__('Delivery tracking needs a public HTTPS site')}
          description={sprintf(
            __('Until your site is reachable over HTTPS, emails will show as “%s” only.'),
            deliveryStatusEntry('accepted').label
          )}
        />
      )
    default:
      return (
        <Text style={{ color: verified ? token.colorSuccessText : token.colorWarningText }}>
          {verified
            ? __('✓ Working — delivery updates are arriving')
            : __('Waiting for the first delivery update')}
        </Text>
      )
  }
}

/** Copyable webhook URL with its security note, or the "save first" placeholder. */
function WebhookAddress({ url }: { url: string }) {
  if (!url) {
    return (
      <Text type="secondary" style={{ display: 'block', marginBottom: 8 }}>
        {__('Save this connection to get its webhook address.')}
      </Text>
    )
  }

  return (
    <>
      <Paragraph copyable={{ text: url }} style={{ marginBottom: 4, wordBreak: 'break-all' }}>
        {url}
      </Paragraph>
      <Text type="secondary" style={{ display: 'block', marginBottom: 8 }}>
        {__('Keep this address private, like a password.')}
      </Text>
    </>
  )
}

/** Delivery-tracking panel: webhook address, set-up guidance, health, the provider's signing-secret fields and a manual create action. */
export default function WebhookPanel({
  connection,
  provider,
  trackingFields
}: {
  connection: Connection
  provider: ProviderMeta
  trackingFields: FieldMeta[]
}) {
  const { token } = theme.useToken()
  const secret = (connection.settings?.webhook_secret as string | undefined) ?? ''
  const webhookUrl =
    connection.webhook_url || (secret ? `${config.ROOT_URL}/bit-smtp/${connection.id}/${secret}` : '')
  const verified = Boolean(connection.settings?.webhook_verified)
  const [creating, setCreating] = useState(false)

  /** Asks the backend to create the webhook in the provider and toasts the outcome. */
  const createProviderWebhook = async () => {
    setCreating(true)
    try {
      const response = await request<{ created: boolean; id: string }>({
        action: 'mail/connections/webhook/create',
        data: { id: connection.id }
      })
      if (response.status === 'success') {
        notify.success(response.message || __('Delivery tracking is ready'))
      } else {
        notify.error(response.message || __("Couldn't create the webhook"))
      }
    } catch {
      notify.error(__("Couldn't create the webhook"))
    } finally {
      setCreating(false)
    }
  }

  return (
    <div
      style={{
        border: `1px solid ${token.colorBorderSecondary}`,
        borderRadius: token.borderRadius,
        padding: 12,
        marginTop: 12
      }}
    >
      {webhookUrl ? (
        <Text type="secondary" style={{ display: 'block', marginBottom: 4 }}>
          {setupInstruction(provider, connection.webhook_provisioning)}
        </Text>
      ) : null}
      <WebhookAddress url={webhookUrl} />
      <WebhookHealth
        provisioning={connection.webhook_provisioning}
        verified={verified}
        lastEventAt={connection.settings?.webhook_last_event_at as string | undefined}
        providerLabel={provider.label}
      />
      {trackingFields.length > 0 ? (
        <div style={{ marginTop: 12 }}>
          <ProviderFields fields={trackingFields} />
        </div>
      ) : null}
      {provider.supports_webhook_provisioning === true && connection.id && (
        <Button
          size="small"
          loading={creating}
          onClick={createProviderWebhook}
          style={{ marginTop: 10 }}
        >
          {sprintf(__('Create webhook in %s'), provider.label)}
        </Button>
      )}
      <Text type="secondary" style={{ display: 'block', fontSize: token.fontSizeSM, marginTop: 8 }}>
        {__(
          'Test emails sent from your provider’s dashboard don’t count; a real email from your site does.'
        )}
      </Text>
    </div>
  )
}
