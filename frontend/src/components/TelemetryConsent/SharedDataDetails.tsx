import { CheckOutlined, StopOutlined } from '@ant-design/icons'
import { DOCS } from '@common/docs'
import { __ } from '@common/helpers/i18nwrap'
import { NewTabLink } from '@components/FieldHelp'
import { Flex, Typography, theme } from 'antd'

const { Text } = Typography

/** Everything the weekly report sends, matching wp-telemetry's getTrackingData(). */
function sharedItems(): string[] {
  return [
    __('Your site name and web address'),
    __('The site admin email and the first admin’s name'),
    __('Your server’s public IP address'),
    __('Versions of WordPress, PHP and MySQL, plus your theme, language and a few server settings'),
    __('How many users your site has, by role'),
    __('The names and versions of your active plugins'),
    __('How many inactive plugins you have and how many successful test emails you sent')
  ]
}

/** What the report never contains. */
function neverSharedItems(): string[] {
  return [
    __('What your emails say'),
    __('Who you send email to'),
    __('Your email logs'),
    __('Your passwords, API keys or other login details')
  ]
}

/** One titled list with an icon per row. */
function ItemList({ title, items, icon }: { title: string; items: string[]; icon: React.ReactNode }) {
  const { token } = theme.useToken()
  return (
    <Flex vertical gap={token.marginXXS}>
      <Text strong>{title}</Text>
      <Flex
        component="ul"
        vertical
        gap={token.marginXXS}
        style={{ margin: 0, padding: 0, listStyle: 'none' }}
      >
        {items.map(item => (
          <Flex component="li" key={item} gap={token.marginXS} align="baseline">
            {icon}
            <Text>{item}</Text>
          </Flex>
        ))}
      </Flex>
    </Flex>
  )
}

/** Plain-language list of what usage sharing sends and never sends, with the privacy policy link. */
export default function SharedDataDetails() {
  const { token } = theme.useToken()
  return (
    <Flex vertical gap={token.marginSM}>
      <ItemList
        title={__('What we get')}
        items={sharedItems()}
        icon={<CheckOutlined aria-hidden="true" style={{ color: token.colorPrimary }} />}
      />
      <ItemList
        title={__('What we never get')}
        items={neverSharedItems()}
        icon={<StopOutlined aria-hidden="true" style={{ color: token.colorTextSecondary }} />}
      />
      <Text type="secondary">
        {__('If you say yes, this is sent once now and then about once a week.')}{' '}
        <NewTabLink href={DOCS.privacyPolicy}>{__('Read our privacy policy')}</NewTabLink>
      </Text>
    </Flex>
  )
}
