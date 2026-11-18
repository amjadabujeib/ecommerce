// Disposable local SMTP protocol fixture. Never forwards mail externally.
import net from 'node:net';
import fs from 'node:fs';
net.createServer(socket=>{
    socket.write('220 fixture.local ESMTP\r\n');let buffer='',data=false,mail='';
    socket.on('data',chunk=>{buffer+=chunk.toString();let end;
        while((end=buffer.indexOf('\r\n'))>=0){const line=buffer.slice(0,end);buffer=buffer.slice(end+2);
            if(data){if(line!=='.'){mail+=line+'\r\n';continue;}data=false;
                fs.writeFileSync('/tmp/receiving','1');
                if(fs.existsSync('/tmp/hold'))continue;
                if(fs.existsSync('/tmp/fail'))socket.write('451 injected transient failure\r\n');
                else{const path='/tmp/mail.json';const mails=fs.existsSync(path)?JSON.parse(fs.readFileSync(path)):[];mails.push(mail);fs.writeFileSync(path,JSON.stringify(mails));socket.write('250 accepted\r\n');}mail='';
            }else if(/^EHLO|^HELO/.test(line))socket.write('250 fixture.local\r\n');
            else if(line==='DATA'){data=true;socket.write('354 end with dot\r\n');}
            else if(line==='QUIT'){socket.end('221 bye\r\n');}
            else socket.write('250 ok\r\n');
        }
    });socket.on('error',()=>{});
}).listen(2525,'0.0.0.0');
