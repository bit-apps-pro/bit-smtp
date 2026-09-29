import { __ } from '@common/helpers/i18nwrap'
import { Button, Card, Col, Flex, Row, Typography, theme } from 'antd'
import PromoBanner from './PromoBanner'
import { pluginsData } from './pluginsData'

const { Title, Text } = Typography
const { useToken } = theme

/** Cross-promotion page listing other Bit Apps plugins, plus any running campaign. */
export default function Others() {
  const { token } = useToken()

  return (
    <Flex vertical gap="middle" style={{ padding: token.paddingLG, width: '100%', overflowX: 'auto' }}>
      <Flex align="center" justify="space-between" wrap gap="middle">
        <Title level={4} style={{ margin: 0 }}>
          {__('More plugins from Bit Apps')}
        </Title>
        <PromoBanner />
      </Flex>
      <Row gutter={[16, 16]}>
        {pluginsData.map(plugin => (
          <Col key={plugin.pluginUrl} xs={24} sm={24} md={12} lg={8} xl={8}>
            <Card style={{ height: '100%' }}>
              <Flex vertical style={{ height: '100%' }} gap="middle">
                <Flex justify="center">
                  <Flex
                    justify="center"
                    align="center"
                    style={{
                      width: 80,
                      height: 80,
                      padding: token.paddingXS,
                      backgroundColor: token.colorFillTertiary,
                      borderRadius: token.borderRadiusLG
                    }}
                  >
                    <img
                      src={plugin.logo}
                      alt=""
                      style={{
                        width: 80,
                        height: 80,
                        objectFit: 'contain'
                      }}
                    />
                  </Flex>
                </Flex>

                <Flex vertical gap="small" flex={1}>
                  <Title level={5} style={{ margin: 0, textAlign: 'center' }}>
                    {plugin.title}
                  </Title>
                  <Text
                    type="secondary"
                    style={{
                      flex: 1,
                      display: '-webkit-box',
                      WebkitLineClamp: 3,
                      WebkitBoxOrient: 'vertical',
                      overflow: 'hidden',
                      textAlign: 'justify',
                      hyphens: 'auto'
                    }}
                  >
                    {plugin.description}
                  </Text>
                </Flex>

                <Flex justify="center" style={{ marginTop: 'auto' }}>
                  <Text type="secondary">
                    {__('Active installs')}: {plugin.activeInstalls}
                  </Text>
                </Flex>
                <Button
                  type="link"
                  href={plugin.pluginUrl}
                  target="_blank"
                  rel="noreferrer"
                  aria-label={`${__('View plugin')}: ${plugin.title}`}
                >
                  {__('View plugin')}
                </Button>
              </Flex>
            </Card>
          </Col>
        ))}
      </Row>
    </Flex>
  )
}
