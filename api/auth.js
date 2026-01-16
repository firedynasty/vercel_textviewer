// Simple auth endpoint to validate access code

module.exports = async function handler(req, res) {
  // Enable CORS
  res.setHeader('Access-Control-Allow-Credentials', true);
  res.setHeader('Access-Control-Allow-Origin', '*');
  res.setHeader('Access-Control-Allow-Methods', 'POST,OPTIONS');
  res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

  if (req.method === 'OPTIONS') {
    res.status(200).end();
    return;
  }

  if (req.method === 'POST') {
    const { accessCode } = req.body;
    const validAccessCode = process.env.ACCESS_CODE;

    if (accessCode && accessCode === validAccessCode) {
      return res.status(200).json({ success: true });
    } else {
      return res.status(401).json({ error: 'Invalid access code' });
    }
  }

  return res.status(405).json({ error: 'Method not allowed' });
}
