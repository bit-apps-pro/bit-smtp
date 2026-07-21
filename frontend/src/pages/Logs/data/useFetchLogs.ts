import request from '@common/helpers/request'
import { useQuery } from '@tanstack/react-query'

export interface LogQueryType {
  searchKeyValue?: string
  pageNo: number
  limit: number
}

export type LogDetail = {
  message: string
  headers: Record<string, string>
  attachments: Array<string>
}
export type LogType = {
  id: number
  status: string
  subject: number
  to_addr: Array<string>
  details: LogDetail
  debug_info: Array<string>
  retry_count: number
  connection: string | null
  created_at: string
  updated_at: string
}

type FetchLogsType = {
  logs: Array<LogType>
  count: number
  current: number
  pages: number
}

export default function useFetchLogs(searchData: LogQueryType) {
  const queryId = `logs-${searchData.pageNo}`
  const { data, isLoading, isFetching, refetch } = useQuery({
    refetchOnWindowFocus: false,
    queryKey: ['all_logs', queryId, searchData],
    queryFn: async ({ signal }) =>
      request<FetchLogsType>({ action: 'logs/all', data: searchData, signal })
  })
  return {
    isLoading,
    refetch,
    isLogsFetching: isFetching,
    logs: data?.data?.logs ?? [],
    total: data?.data?.count ?? 0,
    current: data?.data?.current ?? 0,
    pages: data?.data?.pages ?? 0
  }
}
