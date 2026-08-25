import { triggerBlobDownload } from '@common/helpers/download'
import request from '@common/helpers/request'
import { type LogQueryType } from '@pages/Logs/data/useFetchLogs'
import { useMutation } from '@tanstack/react-query'

/** The logs-list filters, minus pagination — an export always covers the whole filtered set. */
export type LogExportFilters = Omit<LogQueryType, 'pageNo' | 'limit' | 'searchKeyValue'>

export type LogExportResponse = {
  csv: string
  filename: string
  truncated: boolean
  count: number
}

/** POST the active log filters to logs/export and, on success, download the returned CSV. */
export default function useExportLogs() {
  return useMutation({
    mutationFn: async (filters: LogExportFilters) => {
      const response = await request<LogExportResponse>({ action: 'logs/export', data: filters })
      if (response.status === 'success' && response.data?.csv) {
        triggerBlobDownload(
          response.data.filename,
          new Blob([response.data.csv], { type: 'text/csv;charset=utf-8;' })
        )
      }
      return response
    }
  })
}
