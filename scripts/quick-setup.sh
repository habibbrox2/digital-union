#!/bin/bash

# Quick Production Setup Runner
# এটি সরাসরি চালান: bash quick-setup.sh

SERVER_IP="65.21.174.100"
SSH_USER="tdhuedhn"
PROJECT_PATH="/home/tdhuedhn/lgdhaka/ht_docs"

echo "🚀 Starting Production Setup..."
echo ""
echo "Server: $SERVER_IP"
echo "User: $SSH_USER"
echo "Project: $PROJECT_PATH"
echo ""

# Check if SSH is configured
if ! command -v ssh &> /dev/null; then
    echo "❌ SSH not found on your system"
    echo "Please install SSH client first"
    exit 1
fi

# Step 1: Copy the production-setup.sh script to server
echo "📤 Uploading setup script to server..."
if scp production-setup.sh "$SSH_USER@$SERVER_IP:$PROJECT_PATH/" 2>/dev/null; then
    echo "✅ Script uploaded"
else
    echo "❌ Failed to upload script"
    echo "Make sure you can SSH into the server:"
    echo "  ssh $SSH_USER@$SERVER_IP"
    exit 1
fi

echo ""
echo "📋 Running production readiness check..."
echo ""

# Step 2: Run the script on remote server
ssh "$SSH_USER@$SERVER_IP" << 'EOF'
cd /home/tdhuedhn/lgdhaka/ht_docs || exit 1

echo "Current working directory: $(pwd)"
echo ""

# Make script executable
chmod +x production-setup.sh

# Run it
bash production-setup.sh "$@"
EOF

RESULT=$?

echo ""
if [ $RESULT -eq 0 ]; then
    echo "✅ Production setup completed successfully!"
    
    echo ""
    echo "📝 Next Steps:"
    echo "  1. Check the output above for any warnings"
    echo "  2. If issues were found, run with --fix:"
    echo "     bash quick-setup.sh --fix"
    echo "  3. Test deployment:"
    echo "     bash quick-setup.sh --test"
else
    echo "⚠️  Some issues were detected"
    echo ""
    echo "To auto-fix all issues, run:"
    echo "  bash quick-setup.sh --fix"
fi

exit $RESULT
