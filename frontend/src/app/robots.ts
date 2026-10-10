import type { MetadataRoute } from 'next'
import { SITE_URL } from '@/lib/seo'

export default function robots(): MetadataRoute.Robots {
  return {
    rules: [
      {
        userAgent: '*',
        // HTML pages must be crawlable for redirects and noindex to be seen.
        // Authentication and backend authorization protect private content.
        allow: '/',
        disallow: ['/api/', '/ws/'],
      },
    ],
    sitemap: new URL('/sitemap.xml', SITE_URL).href,
  }
}
