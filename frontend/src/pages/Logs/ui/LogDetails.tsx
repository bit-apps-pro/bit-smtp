import { useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import {
  DeleteOutlined,
  DownloadOutlined,
  EditOutlined,
  LeftOutlined,
  SendOutlined
} from '@ant-design/icons'
import { triggerBlobDownload } from '@common/helpers/download'
import { __ } from '@common/helpers/i18nwrap'
import useDeleteLog from '@pages/Logs/data/useDeleteLog'
import useFetchLog from '@pages/Logs/data/useFetchLog'
import useResendLog from '@pages/Logs/data/useResendLog'
import { Button, Card, Space, Tabs, Typography, notification } from 'antd'
import EditResendModal from './EditResendModal'
import DebugOutputTab from './tabs/DebugOutputTab'
import DeliveryAttemptsTab from './tabs/DeliveryAttemptsTab'
import DeliveryStatusTab from './tabs/DeliveryStatusTab'
import EmailDetailsTab from './tabs/EmailDetailsTab'
import MailBodyTab from './tabs/MailBodyTab'

const { Title } = Typography

export default function LogDetails() {
  const { id } = useParams()
  const navigate = useNavigate()
  const logId = Number(id)

  const { log, isLoading } = useFetchLog(logId)
  const { deleteLog, isLogDeleting } = useDeleteLog()
  const { resendLog, isResending } = useResendLog()
  const [isEditResendOpen, setEditResendOpen] = useState(false)

  const handleDelete = async () => {
    if (!log) return
    try {
      const res = await deleteLog([log.id])
      if (res.code === 'SUCCESS') {
        notification.success({ message: res.message || __('Log deleted') })
        navigate(-1)
      } else {
        notification.error({ message: res.message || __('Failed to delete log') })
      }
    } catch (e) {
      notification.error({ message: __('Failed to delete log') })
    }
  }

  const handleResend = async () => {
    if (!log) return
    try {
      const res = await resendLog(log.id)
      if (res?.code === 'SUCCESS') {
        notification.success({ message: res.message || __('Mail resent') })
      } else {
        notification.error({ message: res?.message || __('Failed to resend mail') })
      }
    } catch (e) {
      notification.error({ message: __('Failed to resend mail') })
    }
  }

  const handleExport = () => {
    if (!log) return
    triggerBlobDownload(
      `log-${log.id}.json`,
      new Blob([JSON.stringify(log, null, 2)], { type: 'application/json' })
    )
  }

  return (
    <Card
      loading={isLoading}
      title={
        <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
          <Button type="link" icon={<LeftOutlined />} onClick={() => navigate('/logs')}>
            Back
          </Button>
          <Title level={4} style={{ margin: 0 }}>
            Log Details #{id}
          </Title>
        </div>
      }
      extra={
        <Space>
          <Button icon={<DownloadOutlined />} onClick={handleExport} title={__('Download')} />
          <Button
            icon={<SendOutlined />}
            onClick={handleResend}
            loading={isResending}
            title={__('Resend')}
          />
          <Button
            icon={<EditOutlined />}
            onClick={() => setEditResendOpen(true)}
            title={__('Edit & Resend')}
          />
          <Button
            danger
            icon={<DeleteOutlined />}
            onClick={handleDelete}
            loading={isLogDeleting}
            title={__('Delete')}
          />
        </Space>
      }
    >
      {isLoading || !log ? null : (
        <Tabs
          defaultActiveKey="1"
          items={[
            {
              key: '1',
              label: __('Email Details'),
              children: <EmailDetailsTab isLoading={isLoading} log={log} />
            },
            ...((log.details?.attempts && log.details.attempts.length > 1) ||
            (log.details?.routing_skipped && log.details.routing_skipped.length > 0)
              ? [
                  {
                    key: '2',
                    label: __('Delivery Attempts'),
                    children: <DeliveryAttemptsTab log={log} />
                  }
                ]
              : []),
            ...(log.delivery_verified
              ? [
                  {
                    key: '5',
                    label: __('Delivery'),
                    children: <DeliveryStatusTab log={log} />
                  }
                ]
              : []),
            {
              key: '3',
              label: __('Mail Body'),
              children: <MailBodyTab log={log} />
            },
            {
              key: '4',
              label: __('Debug Output'),
              children: <DebugOutputTab log={log} />
            }
          ]}
        />
      )}
      {log && (
        <EditResendModal log={log} open={isEditResendOpen} onClose={() => setEditResendOpen(false)} />
      )}
    </Card>
  )
}
