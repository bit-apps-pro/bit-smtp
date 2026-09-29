import { Link } from 'react-router-dom'
import { __ } from '@common/helpers/i18nwrap'
import { Button, Empty } from 'antd'

interface LogsEmptyStateProps {
  filtered: boolean
  onClearFilters: () => void
}

/** Logs table empty state: a clear-filters action when filters hide rows, else a first-send prompt. */
export default function LogsEmptyState({ filtered, onClearFilters }: LogsEmptyStateProps) {
  if (filtered) {
    return (
      <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={__('No logs match these filters.')}>
        <Button onClick={onClearFilters}>{__('Clear filters')}</Button>
      </Empty>
    )
  }

  return (
    <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={__('No emails logged yet.')}>
      <Link to="/test-mail">{__('Send a test email')} →</Link>
    </Empty>
  )
}
