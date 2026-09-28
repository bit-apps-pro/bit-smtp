/* eslint-disable @typescript-eslint/no-explicit-any */
import { type Interpolation, type Theme } from '@emotion/react'
import { type GlobalToken } from 'antd'

const styleMap = {
  m: 'margin',
  mt: 'marginTop',
  mr: 'marginRight',
  mb: 'marginBottom',
  ml: 'marginLeft',
  mx: 'marginInline',
  my: 'marginBlock',
  p: 'padding',
  pt: 'paddingTop',
  pr: 'paddingRight',
  pb: 'paddingBottom',
  pl: 'paddingLeft',
  px: 'paddingInline',
  py: 'paddingBlock',
  w: 'width',
  h: 'height',
  d: 'display',
  pos: 'position',
  top: 'top',
  r: 'right',
  b: 'bottom',
  l: 'left',
  z: 'zIndex',
  bg: 'backgroundColor',
  brs: 'borderRadius',
  bdr: 'border',
  sdw: 'boxShadow',
  cur: 'cursor',
  clr: 'color',
  gp: 'gap',
  ta: 'textAlign',
  dis: 'display',
  ai: 'alignItems',
  jc: 'justifyContent',
  dir: 'flexDirection'
} as const

type ShortProp = keyof typeof styleMap

type StyleMapValue = (typeof styleMap)[keyof typeof styleMap]

type StyleObjValue = keyof GlobalToken | string | number | false | undefined

type StyleObj = {
  [key in ShortProp]?: StyleObjValue
}

type GeneratedStyles = {
  [key in StyleMapValue]?: string
}

/** Curried Tailwind-style shorthand-props-to-emotion-styles converter, resolving values against theme tokens. */
const ut =
  (styleObj: StyleObj) =>
  ({ token }: { token: GlobalToken }) => {
    const generatedStyles: GeneratedStyles = {}
    const props = Object.keys(styleObj)

    for (let i = 0; i < props.length; i += 1) {
      const prop = props[i] as ShortProp
      if (prop && styleMap[prop] && styleObj[prop]) {
        const fullProp = styleMap[prop]
        const value = parseValue(styleMap[prop], styleObj[prop], token)
        generatedStyles[fullProp] = value
      }
    }
    return generatedStyles as Interpolation<Theme>
  }

/** Resolves a shorthand style value to a CSS-ready string (theme token lookup, px suffix, `*` → !important). */
function parseValue(property: string, value: StyleObjValue, tokens: any): string {
  if (value === undefined || value === null) {
    return 'unknown'
  }
  if (property === 'zIndex' && typeof value === 'number') {
    return value.toString()
  }
  let updateValue = value

  if (value && tokens[value]) {
    updateValue = tokens[value]
  }
  if (typeof updateValue === 'number') {
    updateValue = `${updateValue}px`
  }
  if (typeof value === 'string' && value.endsWith('*')) {
    updateValue = `${value.replace('*', '')}!important`
  }
  return updateValue.toString()
}

export default ut
