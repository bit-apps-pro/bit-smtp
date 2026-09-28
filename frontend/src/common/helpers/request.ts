import notify from '@components/Toaster/Toaster'
import config from '@config/config'
import { __ } from './i18nwrap'

/* eslint-disable no-restricted-syntax */
/* eslint-disable no-undef */

type MethodType = 'POST' | 'GET'

interface OptionsType {
  method: MethodType
  headers: Record<string, string>
  body?: string | FormData
  signal?: AbortSignal
}

interface QueryParam {
  [key: string]: string | number
}

interface DefaultResponse {
  created_at: string
  updated_at: string
}

interface RequestOptionsType {
  action: string
  data?: Record<string, unknown> | FormData | null | any // eslint-disable-line @typescript-eslint/no-explicit-any
  queryParam?: QueryParam | null
  method?: MethodType
  signal?: AbortSignal
}

export type ApiResponseType = Record<string, string | number>

export interface Response<T> {
  status: 'success' | 'error'
  data: T extends DefaultResponse ? T : T & DefaultResponse
  code: 'SUCCESS' | 'ERROR'
  message: string | undefined
}

/** Send a nonce-authenticated fetch to the plugin's REST action, tolerating non-JSON/malformed responses. */
export default async function request<T>({
  action,
  data,
  queryParam,
  method = 'POST',
  signal
}: RequestOptionsType): Promise<Response<T>> {
  const { API_URL, NONCE } = config
  const uri = new URL(`${API_URL}/${action}`, `${window.location.protocol}//${window.location.host}`)

  if (queryParam !== null) {
    for (const key in queryParam) {
      if (key) {
        uri.searchParams.append(key, queryParam[key as keyof QueryParam].toString())
      }
    }
  }

  const options: OptionsType = {
    method,
    headers: { 'x-wp-nonce': NONCE }
  }

  if (method.toLowerCase() === 'post') {
    options.body = data instanceof FormData ? data : JSON.stringify(data)
  }

  options.signal = signal
  return (await fetch(uri, options)
    .then(res => res.text())
    .then(res => {
      try {
        return JSON.parse(res)
      } catch (error) {
        const parsedRes = extractJSON(res)
        const parsedData = (
          parsedRes === ''
            ? { code: 'ERROR', message: __('Failed to parsed response'), data: res }
            : parsedRes
        ) as Response<unknown>

        if (parsedData.code === 'ERROR') {
          notify.error(parsedData.message as string)
        }

        return parsedData
      }
    })) as Response<T>
}

/** Extract the first balanced-braces JSON object embedded in a string, or null if none is found. */
function extractJSON(str: string) {
  if (typeof str !== 'string') return null

  let braceCount = 0
  let start = -1

  for (let i = 0; i < str.length; i += 1) {
    if (str[i] === '{') {
      if (braceCount === 0) {
        start = i
      }
      braceCount += 1
    } else if (str[i] === '}') {
      braceCount -= 1
      if (braceCount === 0 && start !== -1) {
        const jsonStr = str.substring(start, i + 1)
        try {
          return JSON.parse(jsonStr)
        } catch (e) {
          return ''
        }
      }
    }
  }

  return null
}
