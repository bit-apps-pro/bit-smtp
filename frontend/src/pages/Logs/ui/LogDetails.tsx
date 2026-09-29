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
import { __, sprintf } from '@common/helpers/i18nwrap'
import { IconButton } from '@components/FieldHelp'
import useDeleteLog from '@pages/Logs/data/useDeleteLog'
import useFetchLog from '@pages/Logs/data/useFetchLog'
import useResendLog from '@pages/Logs/data/useResendLog'
import { Button, Card, Popconfirm, Space, Tabs, Typography, notification } from 'antd'
import EditResendModal from './EditResendModal'
import {
  deleteConfirmCopy,
  deleteSuccessMessage,
  resendConfirmCopy,
  resendSuccessMessage
} from './logActionCopy'
import DebugOutputTab from './tabs/DebugOutputTab'
import DeliveryAttemptsTab from './tabs/DeliveryAttemptsTab'
import DeliveryStatusTab from './tabs/DeliveryStatusTab'
import EmailDetailsTab from './tabs/EmailDetailsTab'
import MailBodyTab from './tabs/MailBodyTab'

const { Title } = Typography

const RESEND_COPY = resendConfirmCopy(1)
const DELETE_COPY = deleteConfirmCopy(1)

/** Single log view: detail tabs plus download, confirmed resend, edit-and-resend and confirmed delete. */
export default function LogDetails() {
  const { id } = useParams()
  const navigate = useNavigate()
  const logId = Number(id)

  const { log, isLoading } = useFetchLog(logId)
  const { deleteLog, isLogDeleting } = useDeleteLog()
  const { resendLog, isResending } = useResendLog()
  const [isEditResendOpen, setEditResendOpen] = useState(false)

  /** Deletes this log entry and returns to the previous page on success. */
  const handleDelete = async () => {
    if (!log) return
    try {
      const res = await deleteLog([log.id])
      if (res.code === 'SUCCESS') {
        notification.success({ message: deleteSuccessMessage(1) })
        navigate(-1)
      } else {
        notification.error({ message: res.message || __('Failed to delete log') })
      }
    } catch {
      notification.error({ message: __('Failed to delete log') })
    }
  }

  /** Resends this email exactly as originally sent. */
  const handleResend = async () => {
    if (!log) return
    try {
      const res = await resendLog(log.id)
      if (res?.code === 'SUCCESS') {
        notification.success({ message: resendSuccessMessage(1), description: res.message })
      } else {
        notification.error({ message: res?.message || __('Failed to resend mail') })
      }
    } catch {
      notification.error({ message: __('Failed to resend mail') })
    }
  }

  /** Downloads this log entry as a JSON file. */
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
            {__('Back')}
          </Button>
          <Title level={4} style={{ margin: 0 }}>
            {sprintf(__('Log details #%s'), id)}
          </Title>
        </div>
      }
      extra={
        <Space>
          <IconButton
            icon={<DownloadOutlined />}
            onClick={handleExport}
            label={__('Download this log')}
          />
          <Popconfirm
            title={RESEND_COPY.title}
            description={RESEND_COPY.description}
            okText={RESEND_COPY.okText}
            cancelText={__('Cancel')}
            onConfirm={handleResend}
          >
            <span>
              <IconButton
                icon={<SendOutlined />}
                loading={isResending}
                label={__('Resend this email')}
              />
            </span>
          </Popconfirm>
          <IconButton
            icon={<EditOutlined />}
            onClick={() => setEditResendOpen(true)}
            label={__('Edit and resend')}
          />
          <Popconfirm
            title={DELETE_COPY.title}
            description={DELETE_COPY.description}
            okText={DELETE_COPY.okText}
            okButtonProps={{ danger: true }}
            cancelText={__('Cancel')}
            onConfirm={handleDelete}
          >
            <span>
              <IconButton
                danger
                icon={<DeleteOutlined />}
                loading={isLogDeleting}
                label={__('Delete this log')}
              />
            </span>
          </Popconfirm>
        </Space>
      }
    >
      {isLoading || !log ? null : (
        <Tabs
          defaultActiveKey="1"
          items={[
            {
              key: '1',
              label: __('Email details'),
              children: <EmailDetailsTab isLoading={isLoading} log={log} />
            },
            ...((log.details?.attempts && log.details.attempts.length > 1) ||
            (log.details?.routing_skipped && log.details.routing_skipped.length > 0)
              ? [
                  {
                    key: '2',
                    label: __('Delivery attempts'),
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
              label: __('Mail body'),
              children: <MailBodyTab log={log} />
            },
            {
              key: '4',
              label: __('Debug output'),
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
