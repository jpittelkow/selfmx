/** @type {import('next').NextConfig} */
const nextConfig = {
  output: 'standalone',
  reactStrictMode: true,
  
  // Next.js 16+ uses Turbopack by default
  // Turbopack handles file watching automatically, including Docker volumes
  turbopack: {
    resolveAlias: {
      // Turbopack cannot resolve the "browser" condition in @novu/react's
      // exports map, causing "Module not found". Use a relative POSIX path
      // to bypass exports map resolution (Turbopack doesn't support absolute
      // Windows paths).
      '@novu/react': './node_modules/@novu/react/dist/esm/index.js',
    },
  },
  
  // API rewrites for development
  // Note: Next.js API routes (app/api/*) take precedence over rewrites
  async rewrites() {
    return process.env.NODE_ENV === 'development'
      ? [
          {
            source: '/api/:path*',
            destination: 'http://localhost:8000/api/:path*',
          },
          {
            source: '/sanctum/:path*',
            destination: 'http://localhost:8000/sanctum/:path*',
          },
          {
            source: '/broadcasting/:path*',
            destination: 'http://localhost:8000/broadcasting/:path*',
          },
        ]
      : [];
  },

  // Environment variables exposed to the client
  env: {
    NEXT_PUBLIC_APP_NAME: process.env.NEXT_PUBLIC_APP_NAME || 'selfmx',
    NEXT_PUBLIC_API_URL: process.env.NEXT_PUBLIC_API_URL || process.env.APP_URL || '',
    NEXT_PUBLIC_REVERB_APP_KEY: process.env.NEXT_PUBLIC_REVERB_APP_KEY || '',
    NEXT_PUBLIC_REVERB_HOST: process.env.NEXT_PUBLIC_REVERB_HOST || '',
    NEXT_PUBLIC_REVERB_PORT: process.env.NEXT_PUBLIC_REVERB_PORT || '',
    NEXT_PUBLIC_REVERB_SCHEME: process.env.NEXT_PUBLIC_REVERB_SCHEME || '',
  },
};

module.exports = nextConfig;
