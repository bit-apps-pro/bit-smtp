import { type CSSProperties, useId, useState } from 'react'
import { SearchOutlined } from '@ant-design/icons'
import { __ } from '@common/helpers/i18nwrap'
import { type ProviderGroup, type ProviderMeta } from '@pages/Connections/types'
import { Alert, Empty, Flex, Input, Modal, Spin, Tag, Typography, theme } from 'antd'
import ProviderLogo from './ProviderLogo'
import cls from './ProviderSelectorModal.module.css'
import useProviders from './data/useProviders'
import { getProviderVisual } from './providerVisuals'

const { Text, Title } = Typography

const LOGO_SIZE = 36
const MODAL_WIDTH = 760
const GROUP_ORDER: (ProviderGroup | 'other')[] = ['mailbox', 'transactional', 'server', 'other']

type TileVars = CSSProperties & Record<`--psm-${string}`, string>

/** Heading and one-line explanation for each picker group. */
function groupCopy(group: ProviderGroup | 'other'): { title: string; hint: string } {
  switch (group) {
    case 'mailbox':
      return {
        title: __('Use your own mailbox'),
        hint: __('Send from an email account you already have.')
      }
    case 'transactional':
      return {
        title: __('Email sending services'),
        hint: __('Best for receipts, orders and password resets. You verify your domain once.')
      }
    case 'server':
      return {
        title: __('Your web server'),
        hint: __('No account needed, but emails often land in spam.')
      }
    default:
      return { title: __('Other providers'), hint: '' }
  }
}

/** Plain-words tag for how the user connects: sign-in, API key, SMTP login, or nothing. */
function connectTag(provider: ProviderMeta): string {
  if (provider.oauth_redirect_url) {
    return __('Sign in')
  }
  switch (provider.kind) {
    case 'api':
      return __('API key')
    case 'smtp':
      return __('Username & password')
    case 'local':
      return __('No setup')
    default:
      return provider.kind
  }
}

/** Case-insensitive match on the provider's name, key, "best for" line and blurb. */
function matchesQuery(provider: ProviderMeta, query: string): boolean {
  const needle = query.trim().toLowerCase()
  if (needle === '') {
    return true
  }
  const haystack = [
    provider.label,
    provider.key,
    provider.bestFor ?? '',
    getProviderVisual(provider.key).blurb
  ]
  return haystack.some(text => text.toLowerCase().includes(needle))
}

/** Providers bucketed by picker group, in display order, skipping empty groups. */
function groupProviders(providers: ProviderMeta[]) {
  return GROUP_ORDER.map(group => ({
    group,
    providers: providers.filter(provider => (provider.group ?? 'other') === group)
  })).filter(bucket => bucket.providers.length > 0)
}

/** One selectable provider: logo, name, "best for" line and connection-type tags. */
function ProviderTile({
  provider,
  style,
  onSelect
}: {
  provider: ProviderMeta
  style: TileVars
  onSelect: (providerKey: string) => void
}) {
  const nameId = useId()
  const detailId = useId()
  const visual = getProviderVisual(provider.key, provider.label)

  return (
    <button
      type="button"
      className={cls.tile}
      style={style}
      aria-labelledby={nameId}
      aria-describedby={detailId}
      onClick={() => onSelect(provider.key)}
    >
      <ProviderLogo visual={visual} size={LOGO_SIZE} />
      <Flex vertical gap={4} className={cls.tileText}>
        <Text strong id={nameId}>
          {provider.label}
        </Text>
        <Flex vertical gap={6} id={detailId}>
          <Text type="secondary" className={cls.bestFor}>
            {provider.bestFor ?? visual.blurb}
          </Text>
          <Flex gap={4} wrap>
            <Tag className={cls.tag}>{connectTag(provider)}</Tag>
            {provider.group === 'server' ? (
              <Tag className={cls.tag} color="warning">
                {__('Not recommended')}
              </Tag>
            ) : null}
          </Flex>
        </Flex>
      </Flex>
    </button>
  )
}

/** "Not sure which to choose?" guidance shown above the groups. */
function ChoosingHelp() {
  return (
    <Alert
      type="info"
      showIcon
      message={__('Not sure which to choose?')}
      description={__(
        'For receipts and password resets, pick a sending service like Brevo. For a few emails a day, your own mailbox is fine.'
      )}
    />
  )
}

/** Searchable, grouped provider picker with plain-language guidance on which kind to choose. */
export default function ProviderSelectorModal({
  open,
  onClose,
  onSelect
}: {
  open: boolean
  onClose: () => void
  onSelect: (providerKey: string) => void
}) {
  const { data: providers, isPending } = useProviders()
  const { token } = theme.useToken()
  const [query, setQuery] = useState('')

  /** Reports the chosen provider and closes the picker. */
  const handleSelect = (providerKey: string) => {
    onSelect(providerKey)
    onClose()
  }

  const tileStyle: TileVars = {
    backgroundColor: token.colorBgContainer,
    borderRadius: token.borderRadiusLG,
    padding: token.paddingSM,
    '--psm-border': token.colorBorderSecondary,
    '--psm-hover-border': token.colorPrimary,
    '--psm-hover-shadow': token.boxShadowSecondary,
    '--psm-focus-color': token.colorPrimary
  }

  const buckets = groupProviders((providers ?? []).filter(provider => matchesQuery(provider, query)))

  return (
    <Modal
      title={__('Choose a provider')}
      open={open}
      onCancel={onClose}
      afterClose={() => setQuery('')}
      footer={null}
      width={MODAL_WIDTH}
    >
      {isPending ? (
        <Flex justify="center" style={{ padding: token.paddingLG }}>
          <Spin size="large" />
        </Flex>
      ) : (
        <Flex vertical gap="middle">
          <Input
            allowClear
            autoFocus
            prefix={<SearchOutlined aria-hidden="true" />}
            placeholder={__('Search providers, e.g. Gmail or Brevo')}
            aria-label={__('Search providers')}
            value={query}
            onChange={event => setQuery(event.target.value)}
          />
          {query.trim() === '' ? <ChoosingHelp /> : null}
          {buckets.length === 0 ? (
            <Empty description={__('No providers match your search.')} />
          ) : (
            buckets.map(({ group, providers: members }) => {
              const copy = groupCopy(group)
              return (
                <section key={group} aria-label={copy.title}>
                  <Title level={5} style={{ marginBottom: 0 }}>
                    {copy.title}
                  </Title>
                  {copy.hint ? (
                    <Text type="secondary" style={{ display: 'block', marginBottom: token.marginXS }}>
                      {copy.hint}
                    </Text>
                  ) : null}
                  <div className={cls.grid}>
                    {members.map(provider => (
                      <ProviderTile
                        key={provider.key}
                        provider={provider}
                        style={tileStyle}
                        onSelect={handleSelect}
                      />
                    ))}
                  </div>
                </section>
              )
            })
          )}
        </Flex>
      )}
    </Modal>
  )
}
