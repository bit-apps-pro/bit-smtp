import { __ } from '@common/helpers/i18nwrap'
import { Button, Card, Col, Flex, Row, Typography, theme } from 'antd'
import { pluginsData } from './pluginsData'

const { Title, Text } = Typography
const { useToken } = theme

export default function Others() {
  const { token } = useToken()

  return (
    <Flex vertical gap="middle" style={{ padding: token.paddingLG, width: '100%', overflowX: 'auto' }}>
      <Title level={4} style={{ margin: 0 }}>
        {__('Others')}
      </Title>
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
                      alt={`${plugin.title} logo`}
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
                    {__('Active Installs')}: {plugin.activeInstalls}
                  </Text>
                </Flex>
                <Button key="go-to-plugin" type="link" href={plugin.pluginUrl} target="_blank">
                  {__('Go to Plugin')}
                </Button>
              </Flex>
            </Card>
          </Col>
        ))}
      </Row>
    </Flex>
  )
}
