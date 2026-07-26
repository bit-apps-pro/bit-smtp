/* eslint-disable import/no-extraneous-dependencies */
/// <reference types="vite/client" />
import react from '@vitejs/plugin-react'
import detectPort from 'detect-port'
import fs from 'node:fs'
import type { AddressInfo } from 'node:net'
import path from 'path'
import csso from 'postcss-csso'
import { defineConfig } from 'vite'
import type { Alias, Plugin } from 'vite'
import * as tsconfig from './tsconfig.json'

let chunkCount = 0
function hash() {
  return Math.round(Math.random() * (999 - 1) + 1)
}

function readAliasFromTsConfig(): Alias[] {
  const pathReplaceRegex = /\/\*$/
  return Object.entries(tsconfig.compilerOptions.paths).reduce((aliases, [fromPaths, toPaths]) => {
    const find = fromPaths.replace(pathReplaceRegex, '')
    // eslint-disable-next-line @typescript-eslint/ban-ts-comment
    // @ts-ignore
    const toPath = toPaths[0].replace(pathReplaceRegex, '')
    const replacement = path.resolve(__dirname, toPath)
    aliases.push({ find, replacement })
    return aliases
  }, [] as Alias[])
}

export default defineConfig(({ mode }) => {
  const isDev = mode === 'development'
  const folderName = path.basename(process.cwd())

  return {
    root: 'frontend/src',
    // base: isDev ? `/wp-content/plugins/${folderName}/frontend/src/` : '',
    base: '',
    assetsDir: 'assets',
    plugins: [
      react({
        jsxImportSource: '@emotion/react',
        babel: {
          plugins: ['@emotion/babel-plugin']
        },
        jsxRuntime: 'automatic'
      }),
      setDevServerConfig()

      // babel()
      // !isDev &&
      //   viteStaticCopy({
      //     // structured: true,
      //     targets: [
      //       {
      //         src: normalizePath(path.resolve(__dirname, 'frontend/src/main.tsx')),
      //         dest: 'src'
      //       },
      //       {
      //         src: normalizePath(path.resolve(__dirname, 'frontend/src/icons')),
      //         dest: 'src'
      //       },
      //       {
      //         src: normalizePath(path.resolve(__dirname, 'frontend/src/components/utilities')),
      //         dest: 'src'
      //       },
      //       {
      //         src: normalizePath(path.resolve(__dirname, 'frontend/src/common/hooks')),
      //         dest: 'src'
      //       }
      //     ]
      //   })
    ],
    css: {
      //   modules: {
      //     // root: '.',
      //     generateScopedName: (name) => {
      //       let compressedClassName = nextId()
      //       if (Number.isInteger(Number(compressedClassName[0]))) {
      //         compressedClassName = `_${compressedClassName}`
      //       }
      //       return isProd ? compressedClassName : `${name}_${compressedClassName}`
      //     },
      //   },
      postcss: {
        plugins: [csso()]
      }
    },

    resolve: { alias: readAliasFromTsConfig() },

    build: {
      manifest: true,
      outDir: '../../assets',
      emptyOutDir: true,
      // assetsDir: './',
      rollupOptions: {
        makeAbsoluteExternalsRelative: true,
        input: path.resolve(__dirname, 'frontend/src/main.tsx'),
        output: {
          entryFileNames: `main.${getVersion()}.js`,
          manualChunks: {
            'react-vendor': ['react', 'react-dom'],
            '@emotion/react': ['@emotion/react'],
            '@tanstack/react-query': ['@tanstack/react-query'],
            'react-router-dom': ['react-router-dom'],
            antd: ['antd']
          },

          chunkFileNames: fInfo => {
            if (fInfo.facadeModuleId?.includes('lucide-react')) {
              return 'icons/[name].js'
            }
            return '[name]-[hash].js'
          },

          assetFileNames: fInfo => {
            const pathArr = fInfo?.name?.split('/')
            const fileName = pathArr?.at(-1)

            // console.log(fInfo.name, fileName)

            if (fileName === 'main.css') {
              return `main.${getVersion()}.css`
            }
            if (fileName === 'logo.svg') {
              return 'logo.svg'
            }

            // eslint-disable-next-line no-plusplus
            return `bf-${hash()}-${chunkCount++}.[ext]`
          }
        }
      }
    },
    test: {
      globals: true,
      environment: 'jsdom',
      setupFiles: ['./config/test.setup.ts']
    },
    server: {
      cors: true, // required to load scripts from custom host
      strictPort: true, // strict port to match on PHP side
      port: 3000,
      hmr: { host: 'localhost' }
      // commonjsOptions: { transformMixedEsModules: true },
    }
  }
})

function setDevServerConfig(): Plugin {
  return {
    name: 'vite-plugin-set-dev-server-config',
    async config(_, env) {
      if (env?.mode === 'development') {
        let port = getStoredPort()
        if (!port) {
          port = await detectPort(3000).then((detectedPort: number) => detectedPort)
          updateStoredPort(port)
        }
        return { server: { port, origin: `http://localhost:${port}` } }
      }
      removeStoredPort()
    },
    configureServer(server) {
      if (server.httpServer) {
        server.httpServer.once('listening', () => {
          const { port } = server.httpServer?.address() as AddressInfo
          const storedPort = getStoredPort()
          if (port !== storedPort) {
            updateStoredPort(port)
          }
        })

        server.watcher.add(['port'])

        server.watcher.on('change', (file: string) => {
          if (file === 'port') {
            server.config.logger.warnOnce('Server restarting for origin mismatch', { timestamp: true })
            server.restart()
          }
        })

        server.httpServer.on('close', () => {
          server.watcher.close()
          removeStoredPort()
        })
      }
    }
  }
}

const portFile = path.resolve(__dirname, './port')

function getStoredPort() {
  let port = 0
  if (fs.existsSync(portFile)) {
    port = Number(fs.readFileSync(portFile))
  }

  return port
}

function updateStoredPort(port: number) {
  fs.writeFileSync(portFile, String(port))
}

function removeStoredPort() {
  if (fs.existsSync(portFile)) {
    fs.rmSync(portFile)
  }
}

function getVersion() {
  let version = '1.0.0'
  if (fs.existsSync('readme.txt')) {
    const readme = fs.readFileSync('readme.txt').toString()
    const regex = /Stable\s+tag:\s+(\d+\.\d+(\.?\d+)*)/
    const match = readme.match(regex)
    version = match ? match[1] : '1.0.0'
  }
  return version
}
