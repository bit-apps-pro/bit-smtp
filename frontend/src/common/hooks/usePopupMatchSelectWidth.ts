import { Grid } from 'antd'

/** Whether a picker's dropdown should match its input: always on phones, so it stays on screen; wider screens let long names fit. */
export default function usePopupMatchSelectWidth(): boolean {
  return !Grid.useBreakpoint().sm
}
