import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// https://vitejs.dev/config/
export default defineConfig({
  plugins: [react()],
  build: {
    outDir: 'build',
    rollupOptions: {
      input: 'src/main.jsx',
      output: {
        entryFileNames: 'assets/main.js',
        assetFileNames: 'assets/main.[ext]', // main.css etc
        chunkFileNames: 'assets/[name].js'
      }
    }
  }
})
