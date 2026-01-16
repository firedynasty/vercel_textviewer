// Vercel Serverless Function for File Storage with Blob
// Environment variables (set in Vercel dashboard):
// - BLOB_READ_WRITE_TOKEN: Vercel Blob token (auto-added when you create a Blob store)
// - ACCESS_CODE: Password to unlock edit/save operations

import { put, list, del } from '@vercel/blob';

export default async function handler(req, res) {
  // Enable CORS
  res.setHeader('Access-Control-Allow-Credentials', true);
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'GET,OPTIONS,POST,DELETE');
  res.setHeader('Access-Control-Allow-Headers', 'X-CSRF-Token, X-Requested-With, Accept, Accept-Version, Content-Length, Content-MD5, Content-Type, Date, X-Api-Version');

  if (req.method === 'OPTIONS') {
    res.status(200).end();
    return;
  }

  try {
    // GET - List all files and their contents (no auth required)
    if (req.method === 'GET') {
      const { blobs } = await list({ prefix: 'docs/' });

      // Fetch content for each blob
      const files = {};
      for (const blob of blobs) {
        try {
          const response = await fetch(blob.url);
          const content = await response.text();
          // Remove 'docs/' prefix from pathname for display
          const filename = blob.pathname.replace('docs/', '');
          files[filename] = content;
        } catch (err) {
          console.error(`Error fetching ${blob.pathname}:`, err);
        }
      }

      return res.status(200).json({ files });
    }

    // POST - Save/update a file
    if (req.method === 'POST') {
      const { filename, content } = req.body;

      // Validate input
      if (!filename || typeof filename !== 'string') {
        return res.status(400).json({ error: 'Filename is required' });
      }

      if (content === undefined || content === null) {
        return res.status(400).json({ error: 'Content is required' });
      }

      // Save to Blob with 'docs/' prefix
      const blob = await put(`docs/${filename}`, content, {
        access: 'public',
        addRandomSuffix: false, // Keep exact filename
      });

      console.log(`File saved: ${filename} (${content.length} chars)`);
      return res.status(200).json({ success: true, filename, url: blob.url });
    }

    // DELETE - Remove a file
    if (req.method === 'DELETE') {
      const { filename } = req.query;

      // Validate input
      if (!filename || typeof filename !== 'string') {
        return res.status(400).json({ error: 'Filename is required' });
      }

      // Find the blob URL first
      const { blobs } = await list({ prefix: `docs/${filename}` });
      const blob = blobs.find(b => b.pathname === `docs/${filename}`);

      if (!blob) {
        return res.status(404).json({ error: 'File not found' });
      }

      // Delete the blob
      await del(blob.url);

      console.log(`File deleted: ${filename}`);
      return res.status(200).json({ success: true, filename });
    }

    // Method not allowed
    return res.status(405).json({ error: 'Method not allowed' });

  } catch (error) {
    console.error('Blob API error:', error);
    return res.status(500).json({ error: error.message || 'Internal server error' });
  }
}
